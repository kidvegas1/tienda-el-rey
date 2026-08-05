/**
 * Face ID: detect, match, and normalize any image to JPEG for enrollment.
 */
(function (global) {
    const MODEL_BASE = 'https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/models/';
    const HUMAN_SRC = 'https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/dist/human.js';
    const MATCH_THRESHOLD = 0.58;
    const MATCH_MARGIN = 0.08;
    const MIN_FACE_SCORE = 0.45;
    const MAX_INPUT_BYTES = 12 * 1024 * 1024;

    let human = null;
    let loadPromise = null;

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (global.Human) {
                resolve();
                return;
            }
            const existing = document.querySelector(`script[src="${src}"]`);
            if (existing) {
                existing.addEventListener('load', () => resolve());
                existing.addEventListener('error', () => reject(new Error('Face model script failed')));
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Face model script failed to load'));
            document.head.appendChild(s);
        });
    }

    async function ensureHuman() {
        if (human) return human;
        if (loadPromise) return loadPromise;
        loadPromise = (async () => {
            await loadScript(HUMAN_SRC);
            const HumanCtor = global.Human?.Human || global.Human;
            if (!HumanCtor) throw Object.assign(new Error('Face recognition unavailable'), { code: 'unavailable' });
            human = new HumanCtor({
                modelBasePath: MODEL_BASE,
                cacheModels: true,
                warmup: 'none',
                face: {
                    enabled: true,
                    detector: { rotation: false, maxDetected: 1 },
                    mesh: { enabled: false },
                    iris: { enabled: false },
                    description: { enabled: true },
                    emotion: { enabled: false },
                    antispoof: { enabled: false },
                    liveness: { enabled: false },
                },
                body: { enabled: false },
                hand: { enabled: false },
                object: { enabled: false },
                gesture: { enabled: false },
                segmentation: { enabled: false },
            });
            await human.load();
            return human;
        })();
        try {
            return await loadPromise;
        } catch (e) {
            loadPromise = null;
            throw e;
        }
    }

    function cosineSimilarity(a, b) {
        if (!a?.length || !b?.length || a.length !== b.length) return 0;
        let dot = 0, na = 0, nb = 0;
        for (let i = 0; i < a.length; i++) {
            const x = a[i], y = b[i];
            dot += x * y;
            na += x * x;
            nb += y * y;
        }
        const denom = Math.sqrt(na) * Math.sqrt(nb);
        return denom > 0 ? dot / denom : 0;
    }

    function fail(code, message) {
        const err = new Error(message);
        err.code = code;
        return err;
    }

    function isHeicName(name = '', type = '') {
        const n = String(name).toLowerCase();
        const t = String(type).toLowerCase();
        return n.endsWith('.heic') || n.endsWith('.heif') || t.includes('heic') || t.includes('heif');
    }

    /** Decode File/Blob into HTMLImageElement or ImageBitmap. */
    async function decodeImage(file) {
        if (file.size > MAX_INPUT_BYTES) {
            throw fail('too_large', 'Image is too large (max 12 MB).');
        }
        if (isHeicName(file.name, file.type)) {
            // Some Safari builds can still decode via createImageBitmap
            try {
                if (typeof createImageBitmap === 'function') {
                    return await createImageBitmap(file);
                }
            } catch (_) { /* fall through */ }
            throw fail(
                'heic_unsupported',
                'HEIC/Live Photo not supported. Use camera capture, or set iPhone Camera → Formats → Most Compatible.'
            );
        }
        if (typeof createImageBitmap === 'function') {
            try {
                return await createImageBitmap(file);
            } catch (_) { /* try Image() */ }
        }
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((resolve, reject) => {
                const el = new Image();
                el.onload = () => resolve(el);
                el.onerror = () => reject(fail('decode_failed', 'Could not read this image file. Try JPG/PNG or the camera.'));
                el.src = url;
            });
            return img;
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    function bitmapSize(img) {
        return {
            w: img.videoWidth || img.naturalWidth || img.width || 0,
            h: img.videoHeight || img.naturalHeight || img.height || 0,
        };
    }

    /** Convert any decoded image to JPEG Blob (max edge 1600). */
    async function toJpegBlob(img, quality = 0.88) {
        const { w, h } = bitmapSize(img);
        if (w < 40 || h < 40) {
            throw fail('too_small', 'Image is too small for Face ID.');
        }
        const maxEdge = 1600;
        const scale = Math.min(1, maxEdge / Math.max(w, h));
        const nw = Math.max(1, Math.round(w * scale));
        const nh = Math.max(1, Math.round(h * scale));
        const canvas = document.createElement('canvas');
        canvas.width = nw;
        canvas.height = nh;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, nw, nh);
        if (typeof img.close === 'function') {
            try { img.close(); } catch (_) { /* ImageBitmap */ }
        }
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) throw fail('encode_failed', 'Could not convert image to JPEG.');
        return blob;
    }

    async function detectDescriptor(input) {
        const h = await ensureHuman();
        const result = await h.detect(input);
        const face = result?.face?.[0];
        if (!face?.embedding?.length) {
            return { ok: false, error: 'no_face', code: 'no_face', face: null };
        }
        if ((face.score || 0) < MIN_FACE_SCORE) {
            return { ok: false, error: 'low_quality', code: 'low_quality', face, score: face.score || 0 };
        }
        const descriptor = Array.from(face.embedding).map((n) => Number(n));
        if (descriptor.some((n) => !Number.isFinite(n))) {
            return { ok: false, error: 'bad_descriptor', code: 'bad_descriptor', face };
        }
        return { ok: true, descriptor, score: face.score || 0, face };
    }

    /**
     * Full enrollment prep: any image → JPEG + face descriptor + small thumb data-URL.
     */
    async function prepareEnrollment(fileOrBlob) {
        if (!fileOrBlob) throw fail('no_file', 'No image selected.');
        const file = fileOrBlob;
        const img = await decodeImage(file);
        const jpegBlob = await toJpegBlob(img);
        // Detect on the normalized JPEG for consistency with what we store
        const jpegUrl = URL.createObjectURL(jpegBlob);
        let det;
        try {
            const jpegImg = await new Promise((resolve, reject) => {
                const el = new Image();
                el.onload = () => resolve(el);
                el.onerror = () => reject(fail('decode_failed', 'Normalized JPEG could not be read.'));
                el.src = jpegUrl;
            });
            det = await detectDescriptor(jpegImg);
        } finally {
            URL.revokeObjectURL(jpegUrl);
        }
        if (!det.ok) {
            const msg = det.code === 'low_quality'
                ? 'Face too unclear — move closer or improve lighting.'
                : 'No face detected — use a clear front-facing photo.';
            throw fail(det.code || 'no_face', msg);
        }
        // Small thumb for server fallback
        const thumbCanvas = document.createElement('canvas');
        const tImg = await createImageBitmap(jpegBlob);
        const tw = tImg.width, th = tImg.height;
        const ts = Math.min(1, 320 / Math.max(tw, th));
        thumbCanvas.width = Math.max(1, Math.round(tw * ts));
        thumbCanvas.height = Math.max(1, Math.round(th * ts));
        thumbCanvas.getContext('2d').drawImage(tImg, 0, 0, thumbCanvas.width, thumbCanvas.height);
        tImg.close?.();
        const thumb = thumbCanvas.toDataURL('image/jpeg', 0.82);
        return {
            ok: true,
            jpegBlob,
            descriptor: det.descriptor,
            score: det.score,
            thumbDataUrl: thumb,
        };
    }

    async function detectFromFile(file) {
        try {
            const prepared = await prepareEnrollment(file);
            return {
                ok: true,
                descriptor: prepared.descriptor,
                score: prepared.score,
                jpegBlob: prepared.jpegBlob,
                thumbDataUrl: prepared.thumbDataUrl,
            };
        } catch (e) {
            return { ok: false, error: e.code || 'decode_failed', code: e.code || 'decode_failed', message: e.message };
        }
    }

    function matchDescriptor(descriptor, roster, threshold = MATCH_THRESHOLD, margin = MATCH_MARGIN) {
        const scored = [];
        for (const row of roster || []) {
            const emb = row.descriptor;
            if (!Array.isArray(emb) || !emb.length) continue;
            scored.push({ id: row.id, name: row.name, score: cosineSimilarity(descriptor, emb) });
        }
        scored.sort((a, b) => b.score - a.score);
        const best = scored[0] || null;
        const second = scored[1] || null;
        if (!best || best.score < threshold) {
            return { matched: false, reason: 'below_threshold', best, second };
        }
        if (second && (best.score - second.score) < margin) {
            return { matched: false, reason: 'ambiguous', best, second };
        }
        return { matched: true, best, second };
    }

    async function startCamera(videoEl, facingMode = 'user') {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode, width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false,
        });
        videoEl.srcObject = stream;
        await videoEl.play();
        return stream;
    }

    function stopCamera(stream) {
        if (!stream) return;
        stream.getTracks().forEach((t) => t.stop());
    }

    function captureBlob(videoEl, canvasEl, type = 'image/jpeg', quality = 0.9) {
        return new Promise((resolve, reject) => {
            const w = videoEl.videoWidth || 640;
            const h = videoEl.videoHeight || 480;
            canvasEl.width = w;
            canvasEl.height = h;
            canvasEl.getContext('2d').drawImage(videoEl, 0, 0, w, h);
            canvasEl.toBlob((blob) => {
                if (!blob) reject(fail('encode_failed', 'Camera capture failed.'));
                else resolve(blob);
            }, type, quality);
        });
    }

    global.FaceId = {
        MATCH_THRESHOLD,
        MATCH_MARGIN,
        MIN_FACE_SCORE,
        ensureHuman,
        detectDescriptor,
        detectFromFile,
        prepareEnrollment,
        matchDescriptor,
        cosineSimilarity,
        startCamera,
        stopCamera,
        captureBlob,
        isHeicName,
    };
})(typeof window !== 'undefined' ? window : globalThis);
