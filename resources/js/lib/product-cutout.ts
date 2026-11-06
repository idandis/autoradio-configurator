export function contrastMask(data: Uint8ClampedArray, w: number, h: number, tolerance: number): Uint8Array {
    const n = w * h, background = new Uint8Array(n), queue = new Int32Array(n);
    let head = 0, tail = 0;
    const light = (i: number) => data[i * 4 + 3] < 16 || Math.min(data[i * 4], data[i * 4 + 1], data[i * 4 + 2]) >= 255 - tolerance;
    const add = (i: number) => { if (!background[i] && light(i)) { background[i] = 1; queue[tail++] = i; } };
    for (let x = 0; x < w; x++) { add(x); add((h - 1) * w + x); }
    for (let y = 0; y < h; y++) { add(y * w); add(y * w + w - 1); }
    while (head < tail) {
        const i = queue[head++], x = i % w;
        if (x > 0) add(i - 1); if (x < w - 1) add(i + 1);
        if (i >= w) add(i - w); if (i < n - w) add(i + w);
    }
    if (tail < n * 0.01) throw new Error('Sfondo chiaro non rilevato. Aumenta la sensibilità oppure usa i punti.');
    const visited = new Uint8Array(background), best = new Uint8Array(n);
    let largest = 0;
    for (let start = 0; start < n; start++) {
        if (visited[start]) continue;
        head = 0; tail = 1; queue[0] = start; visited[start] = 1;
        const visit = (i: number) => { if (!visited[i]) { visited[i] = 1; queue[tail++] = i; } };
        while (head < tail) {
            const i = queue[head++], x = i % w;
            if (x > 0) visit(i - 1); if (x < w - 1) visit(i + 1);
            if (i >= w) visit(i - w); if (i < n - w) visit(i + w);
        }
        if (tail > largest) { best.fill(0); for (let j = 0; j < tail; j++) best[queue[j]] = 1; largest = tail; }
    }
    if (largest < 256) throw new Error('Prodotto non rilevato. Riduci la sensibilità oppure usa i punti.');
    return best;
}
