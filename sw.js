const BUILD_ID = "20260713-7";
const CACHE_PREFIX = "speedytapper-";
const CACHE_NAME = `${CACHE_PREFIX}${BUILD_ID}`;
const MUSIC_ASSET_PATHS = new Set([
  "/assets/audio/neon-circuit-refined.m4a",
  "/assets/audio/deep-current.m4a",
  "/assets/audio/power-grid.m4a",
  "/assets/audio/interactive-neon-circuit-refined.m4a",
  "/assets/audio/interactive-deep-current.m4a",
  "/assets/audio/interactive-power-grid.m4a",
  "/assets/audio/interactive-notes-neon-circuit-refined.wav",
  "/assets/audio/interactive-notes-deep-current.wav",
  "/assets/audio/interactive-notes-power-grid.wav"
]);
const APP_SHELL = [
  "./index.html",
  `./styles.css?v=${BUILD_ID}`,
  `./manifest.webmanifest?v=${BUILD_ID}`,
  `./src/config.js?v=${BUILD_ID}`,
  `./src/game-engine.js?v=${BUILD_ID}`,
  `./src/input-timing.js?v=${BUILD_ID}`,
  `./src/music-controller.js?v=${BUILD_ID}`,
  `./src/main.js?v=${BUILD_ID}`,
  `./src/profile-client.js?v=${BUILD_ID}`,
  `./src/sound-controller.js?v=${BUILD_ID}`,
  "./assets/icon.svg",
  "./assets/icon-192.png",
  "./assets/icon-512.png",
  "./assets/apple-touch-icon.png",
  "./assets/disco-concrete.png",
  "./assets/disco-tile-overlay.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
            .map((key) => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  );
});

async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME);

  try {
    const response = await fetch(request, { cache: "no-store" });
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) return cached;
    if (request.mode === "navigate") {
      const fallback = await cache.match("./index.html");
      if (fallback) return fallback;
    }
    throw error;
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request, { cache: "no-store" });
  if (response.ok) await cache.put(request, response.clone());
  return response;
}

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;

  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin || requestUrl.pathname.startsWith("/api/")) return;
  if (MUSIC_ASSET_PATHS.has(requestUrl.pathname)) {
    event.respondWith(cacheFirst(event.request));
    return;
  }
  if (requestUrl.pathname.startsWith("/assets/audio/")) {
    event.respondWith(fetch(event.request, { cache: "no-store" }));
    return;
  }

  event.respondWith(networkFirst(event.request));
});
