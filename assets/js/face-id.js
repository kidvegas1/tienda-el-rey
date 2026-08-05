/**
 * Browser face detect + match via @vladmandic/human (CDN).
 * Cosine similarity locally; server only stores descriptors.
 */
(function (global) {
    const MODEL_BASE = 'https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/models/';
    const HUMAN_SRC = 'https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/dist/human.js';
    // Different people in this store scored ~0.41; same person is typically 0.65+.
    // Keep threshold high and require a clear gap vs the runner-up.
    const MATCH_THRESHOLD = 0.58;
    const MATCH_MARGIN = 0.08;
    const MIN_FACE_SCORE = 0.45;

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
            if (!HumanCtor) throw new Error('Human face library unavailable');
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

    async function detectDescriptor(input) {
        const h = await ensureHuman();
        const result = await h.detect(input);
        const face = result?.face?.[0];
        if (!face?.embedding?.length) {
            return { ok: false, error: 'no_face', face: null };
        }
        if ((face.score || 0) < MIN_FACE_SCORE) {
            return { ok: false, error: 'low_quality', face, score: face.score || 0 };
        }
        return {
            ok: true,
            descriptor: Array.from(face.embedding),
            score: face.score || 0,
            face,
        };
    }

    /**
     * Match descriptor against roster.
     * Requires score >= threshold AND clear margin over second-best.
     */
    function matchDescriptor(descriptor, roster, threshold = MATCH_THRESHOLD, margin = MATCH_MARGIN) {
        const scored = [];
        for (const row of roster || []) {
            const emb = row.descriptor;
            if (!Array.isArray(emb) || !emb.length) continue;
            scored.push({
                id: row.id,
                name: row.name,
                score: cosineSimilarity(descriptor, emb),
            });
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

    async function detectFromFile(file) {
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((resolve, reject) => {
                const el = new Image();
                el.onload = () => resolve(el);
                el.onerror = () => reject(new Error('Could not read image'));
                el.src = url;
            });
            return detectDescriptor(img);
        } finally {
            URL.revokeObjectURL(url);
        }
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
        return new Promise((resolve) => {
            const w = videoEl.videoWidth || 640;
            const h = videoEl.videoHeight || 480;
            canvasEl.width = w;
            canvasEl.height = h;
            canvasEl.getContext('2d').drawImage(videoEl, 0, 0, w, h);
            canvasEl.toBlob((blob) => resolve(blob), type, quality);
        });
    }

    global.FaceId = {
        MATCH_THRESHOLD,
        MATCH_MARGIN,
        MIN_FACE_SCORE,
        ensureHuman,
        detectDescriptor,
        detectFromFile,
        matchDescriptor,
        cosineSimilarity,
        startCamera,
        stopCamera,
        captureBlob,
    };
})(typeof window !== 'undefined' ? window : globalThis);
