const BUILD_ID = "20260714-11";
const CACHE_PREFIX = "speedytapper-";
const CACHE_NAME = `${CACHE_PREFIX}${BUILD_ID}`;
const APP_SHELL = [
  "./index.html",
  `./styles.css?v=${BUILD_ID}`,
  `./manifest.webmanifest?v=${BUILD_ID}`,
  `./src/config.js?v=${BUILD_ID}`,
  `./src/early-bootstrap.js?v=${BUILD_ID}`,
  `./src/game-engine.js?v=${BUILD_ID}`,
  `./src/input-timing.js?v=${BUILD_ID}`,
  `./src/pet-catalog.js?v=${BUILD_ID}`,
  `./src/pet-controller.js?v=${BUILD_ID}`,
  `./src/main.js?v=${BUILD_ID}`,
  `./src/music-controller.js?v=${BUILD_ID}`,
  `./src/profile-client.js?v=${BUILD_ID}`,
  `./src/service-worker-registration.js?v=${BUILD_ID}`,
  `./src/sound-controller.js?v=${BUILD_ID}`,
  `./src/theme-audio.js?v=${BUILD_ID}`,
  `./src/theme-catalog.js?v=${BUILD_ID}`,
  "./assets/icon.svg",
  "./assets/icon-192.png",
  "./assets/icon-512.png",
  "./assets/apple-touch-icon.png",
  "./assets/disco-concrete.png",
  "./assets/disco-concrete-lights.png",
  "./assets/disco-tile-overlay.png",
  "./assets/fonts/pixelify-sans-variable.ttf",
  "./assets/pets/misha-climber.png",
  "./assets/pets/misha-sprite.png",
  "./assets/pets/foka-ice-floe.png",
  "./assets/pets/foka-sprite.png",
  "./assets/pets/kesha-perch.png",
  "./assets/pets/kesha-sprite.png",
  "./assets/pets/tauta-bed.png",
  "./assets/pets/tauta-sprite.png",
  "./assets/pets/pancake-sprite.png"
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

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;

  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin || requestUrl.pathname.startsWith("/api/")) return;
  if (requestUrl.pathname.startsWith("/assets/audio/")) {
    event.respondWith(fetch(event.request, { cache: "no-store" }));
    return;
  }

  event.respondWith(networkFirst(event.request));
});
