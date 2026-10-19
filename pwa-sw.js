/**
 * PWA Service Worker - 缓存策略
 *
 * 策略说明：
 * - HTML 入口：不缓存（确保获取最新版本）
 * - 带哈希的 JS/CSS：长期缓存（文件名变化 = 新版本）
 * - 图片资源：缓存 7 天
 * - KV 配置：网络优先 + 离线缓存备份
 * - API 接口：不缓存
 */

const CACHE_PREFIX = 'ar-pwa';
const STATIC_CACHE = `${CACHE_PREFIX}-static-v20261019`;
const CONFIG_CACHE = `${CACHE_PREFIX}-config-v20261019`;

// 匹配带哈希的静态资源 (如: index-a1b2c3d4.js)
const HASHED_ASSET_REGEX = /[.-][a-f0-9]{6,8}\.(js|css|woff2?)$/i;

// 图片资源
const IMAGE_REGEX = /\.(png|jpg|jpeg|gif|svg|webp|ico)$/i;

// 不缓存的路径
const NO_CACHE_PATTERNS = [
    '/api/',
    '/webapi/',
    '/Home/',
    '/socket',
    '/ws',
    'hot-update', // Vite HMR
];

// ==================== 生命周期 ====================

self.addEventListener('install', () => {
    console.log('[PWA-SW] Installing...');
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    console.log('[PWA-SW] Activating...');
    event.waitUntil(
        caches.keys()
        .then(keys => Promise.all(
            keys.filter(key => key.startsWith(CACHE_PREFIX) &&
                key !== STATIC_CACHE &&
                key !== CONFIG_CACHE)
            .map(key => {
                console.log('[PWA-SW] Removing old cache:', key);
                return caches.delete(key);
            })
        ))
        .then(() => self.clients.claim())
    );
});

// ==================== Fetch 拦截 ====================

self.addEventListener('fetch', (event) => {
    const {
        request
    } = event;
    const url = new URL(request.url);

    // 仅处理 GET 请求
    if (request.method !== 'GET') return;

    // 仅处理 http(s) 请求
    if (!url.protocol.startsWith('http')) return;

    // 跳过不缓存的路径
    if (NO_CACHE_PATTERNS.some(pattern => url.pathname.includes(pattern) || url.href.includes(pattern))) {
        return;
    }

    // 1. HTML 导航请求 - 不缓存，始终走网络
    if (request.mode === 'navigate' || request.destination === 'document') {
        return; // 交给浏览器默认处理
    }

    // 2. KV 配置 - 网络优先 + 缓存备份
    if (url.pathname.includes('/kv/') || url.pathname.endsWith('config.js')) {
        event.respondWith(networkFirstWithFallback(request, CONFIG_CACHE));
        return;
    }

    // 3. 带哈希的静态资源 - 长期缓存
    if (HASHED_ASSET_REGEX.test(url.pathname)) {
        event.respondWith(cacheFirstForever(request, STATIC_CACHE));
        return;
    }

    // 4. 图片资源 - 网络优先，避免旧品牌图和缓存的 404 长期残留
    if (IMAGE_REGEX.test(url.pathname) || request.destination === 'image') {
        event.respondWith(networkFirstWithFallback(request, STATIC_CACHE));
        return;
    }

    // 5. 其他静态资源 (字体等)
    if (request.destination === 'font') {
        event.respondWith(cacheFirstForever(request, STATIC_CACHE));
        return;
    }
});

// ==================== 缓存策略 ====================

/**
 * 缓存优先（永久缓存）
 * 适用于：带哈希的 JS/CSS、字体
 */
async function cacheFirstForever(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.error('[PWA-SW] Fetch failed:', request.url);
        throw error;
    }
}

/**
 * 网络优先 + 缓存回退
 * 适用于：KV 配置
 */
async function networkFirstWithFallback(request, cacheName) {
    const cache = await caches.open(cacheName);

    try {
        const response = await fetchWithTimeout(request, 6000);
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.warn('[PWA-SW] Network failed, trying cache:', request.url);
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        throw error;
    }
}

/**
 * Stale-While-Revalidate
 * 适用于：图片（先返回缓存，后台更新）
 */
async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    // 后台更新
    const fetchPromise = fetch(request)
        .then(response => {
            if (response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => null);

    // 有缓存立即返回，否则等待网络
    return cached || fetchPromise;
}

/**
 * 带超时的 fetch
 */
function fetchWithTimeout(request, timeout) {
    return Promise.race([
        fetch(request),
        new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Timeout')), timeout)
        )
    ]);
}

// ==================== 消息通信 ====================

self.addEventListener('message', (event) => {
    const {
        type
    } = event.data || {};

    if (type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (type === 'CLEAR_ALL_CACHE') {
        caches.keys().then(keys => {
            keys.filter(k => k.startsWith(CACHE_PREFIX))
                .forEach(k => caches.delete(k));
        });
    }
});
