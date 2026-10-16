/**
 * WordPlus: private file delivery for Cloudflare R2.
 *
 * Serves files from an R2 bucket to the people your WordPress site signed a
 * link for, through Cloudflare's network. Set up in Cloudflare:
 *   - an R2 bucket binding named R2_BUCKET (the bucket the site uses)
 *   - a secret named WORKER_SECRET (the Worker Secret from the site's storage settings)
 *   - a custom domain is recommended, the workers.dev address also works
 * Optional: a variable CACHE_TTL, seconds a file stays in the edge cache
 * (default 3600).
 *
 * Link shape: https://<worker>/<object key>?exp=<unix>&d=<inline|attachment>&n=<name>&sig=<sig>
 * sig = base64url(HMAC-SHA256(WORKER_SECRET, "v1|" + key + "|" + exp + "|" + d + "|" + n))
 */

const encoder = new TextEncoder();

function base64url(buffer) {
    let binary = '';
    for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function sign(secret, message) {
    const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    return base64url(await crypto.subtle.sign('HMAC', key, encoder.encode(message)));
}

function safeEqual(a, b) {
    const left = encoder.encode(a);
    const right = encoder.encode(b);
    if (left.byteLength !== right.byteLength) return false;
    if (crypto.subtle.timingSafeEqual) return crypto.subtle.timingSafeEqual(left, right);
    let diff = 0;
    for (let i = 0; i < left.byteLength; i++) diff |= left[i] ^ right[i];
    return diff === 0;
}

function plain(status, text) {
    return new Response(text, {
        status,
        headers: {
            'content-type': 'text/plain; charset=utf-8',
            'cache-control': 'no-store',
            'access-control-allow-origin': '*',
            'x-content-type-options': 'nosniff',
        },
    });
}

function disposition(type, name) {
    if (!name) return type;
    const fallback = name.replace(/[^\x20-\x7E]|["\\]/g, '_');
    return `${type}; filename="${fallback}"; filename*=UTF-8''${encodeURIComponent(name)}`;
}

function contentRange(range, size) {
    if (!range) return null;
    let start;
    let end;
    if ('suffix' in range) {
        start = Math.max(0, size - range.suffix);
        end = size - 1;
    } else {
        start = range.offset || 0;
        end = range.length !== undefined ? start + range.length - 1 : size - 1;
    }
    return { start, end: Math.min(end, size - 1) };
}

function finish(response, d, n, exp) {
    const headers = new Headers(response.headers);
    const now = Math.floor(Date.now() / 1000);
    headers.set('content-disposition', disposition(d, n));
    headers.set('x-content-type-options', 'nosniff');
    headers.set('access-control-allow-origin', '*');
    headers.set('access-control-expose-headers', 'Content-Length, Content-Range, Content-Disposition, ETag, Accept-Ranges');
    headers.set('accept-ranges', 'bytes');
    headers.set('cache-control', `private, max-age=${Math.max(0, Math.min(exp - now, 43200))}`);
    headers.set('referrer-policy', 'no-referrer');
    headers.delete('set-cookie');
    return new Response(response.body, { status: response.status, headers });
}

export default {
    async fetch(request, env, ctx) {
        if (request.method === 'OPTIONS') {
            return new Response(null, {
                status: 204,
                headers: {
                    'access-control-allow-origin': '*',
                    'access-control-allow-methods': 'GET, HEAD',
                    'access-control-allow-headers': 'Range, If-None-Match, If-Modified-Since',
                    'access-control-max-age': '86400',
                },
            });
        }

        if (request.method !== 'GET' && request.method !== 'HEAD') {
            return plain(405, 'Method not allowed');
        }

        if (!env.R2_BUCKET || !env.WORKER_SECRET) {
            return plain(500, 'The Worker needs an R2_BUCKET binding and a WORKER_SECRET secret');
        }

        const url = new URL(request.url);
        let key;
        try {
            key = decodeURIComponent(url.pathname.replace(/^\/+/, ''));
        } catch (e) {
            return plain(400, 'Bad path');
        }

        const exp = url.searchParams.get('exp') || '';
        const d = url.searchParams.get('d') || '';
        const n = url.searchParams.get('n') || '';
        const sig = url.searchParams.get('sig') || '';

        if (!key || !/^\d{1,12}$/.test(exp) || (d !== 'inline' && d !== 'attachment') || !sig) {
            return plain(403, 'Invalid link');
        }

        const expires = Number(exp);
        if (expires < Math.floor(Date.now() / 1000)) {
            return plain(403, 'This link has expired');
        }

        const expected = await sign(env.WORKER_SECRET, `v1|${key}|${exp}|${d}|${n}`);
        if (!safeEqual(expected, sig)) {
            return plain(403, 'Invalid link');
        }

        if (request.method === 'HEAD') {
            const object = await env.R2_BUCKET.head(key);
            if (!object) return plain(404, 'File not found');
            const headers = new Headers();
            object.writeHttpMetadata(headers);
            headers.set('etag', object.httpEtag);
            headers.set('content-length', String(object.size));
            headers.set('last-modified', object.uploaded.toUTCString());
            return finish(new Response(null, { status: 200, headers }), d, n, expires);
        }

        const ttl = Math.max(0, parseInt(env.CACHE_TTL || '3600', 10) || 0);
        const cache = caches.default;
        const cacheKey = new Request(`${url.origin}/__bm_cache/${encodeURIComponent(key)}`, { headers: request.headers });

        if (ttl > 0) {
            const cached = await cache.match(cacheKey);
            if (cached) {
                // A deleted or replaced file must stop opening at once, so the
                // cached copy is only served while R2 still holds that version.
                const current = await env.R2_BUCKET.head(key);
                if (current && current.httpEtag === cached.headers.get('etag')) {
                    return finish(cached, d, n, expires);
                }
                ctx.waitUntil(cache.delete(new Request(cacheKey.url)));
                if (!current) {
                    return plain(404, 'File not found');
                }
            }
        }

        const object = await env.R2_BUCKET.get(key, {
            range: request.headers,
            onlyIf: request.headers,
        });

        if (object === null) {
            return plain(404, 'File not found');
        }

        const headers = new Headers();
        object.writeHttpMetadata(headers);
        headers.set('etag', object.httpEtag);
        headers.set('last-modified', object.uploaded.toUTCString());

        if (!('body' in object) || !object.body) {
            return finish(new Response(null, { status: request.headers.has('if-none-match') || request.headers.has('if-modified-since') ? 304 : 412, headers }), d, n, expires);
        }

        const range = request.headers.has('range') ? contentRange(object.range, object.size) : null;

        if (range) {
            headers.set('content-range', `bytes ${range.start}-${range.end}/${object.size}`);
            headers.set('content-length', String(range.end - range.start + 1));
            return finish(new Response(object.body, { status: 206, headers }), d, n, expires);
        }

        headers.set('content-length', String(object.size));

        if (ttl > 0) {
            const [forCache, forClient] = object.body.tee();
            const cachedHeaders = new Headers(headers);
            cachedHeaders.set('cache-control', `public, max-age=${ttl}`);
            ctx.waitUntil(cache.put(new Request(cacheKey.url), new Response(forCache, { status: 200, headers: cachedHeaders })));
            return finish(new Response(forClient, { status: 200, headers }), d, n, expires);
        }

        return finish(new Response(object.body, { status: 200, headers }), d, n, expires);
    },
};
