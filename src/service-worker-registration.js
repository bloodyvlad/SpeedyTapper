(() => {
  const buildId = "20260713-16";
  window.speedyTapperWorkerReady = Promise.resolve(false);
  if (!("serviceWorker" in navigator)) return;

  const replaceExistingWorker = Boolean(navigator.serviceWorker.controller);
  let refreshing = false;
  const hasCurrentWorker = () => {
    const scriptUrl = navigator.serviceWorker.controller?.scriptURL;
    if (!scriptUrl) return false;
    try {
      return new URL(scriptUrl).searchParams.get("v") === buildId;
    } catch {
      return false;
    }
  };
  const waitForCurrentWorker = () => {
    if (hasCurrentWorker()) return Promise.resolve(true);
    return new Promise((resolve) => {
      const handleControllerChange = () => {
        if (!hasCurrentWorker()) return;
        navigator.serviceWorker.removeEventListener("controllerchange", handleControllerChange);
        resolve(true);
      };
      navigator.serviceWorker.addEventListener("controllerchange", handleControllerChange);
    });
  };
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (!replaceExistingWorker || refreshing) return;
    const reloadKey = `speedytapper-worker-reload-${buildId}`;
    try {
      if (sessionStorage.getItem(reloadKey) === "1") return;
      sessionStorage.setItem(reloadKey, "1");
    } catch {
      // A reload is still safe when session storage is unavailable.
    }
    refreshing = true;
    window.location.reload();
  });

  window.speedyTapperWorkerReady = (async () => {
    try {
      const registration = await navigator.serviceWorker.register(`./sw.js?v=${buildId}`, {
        updateViaCache: "none"
      });
      await registration.update();
      await navigator.serviceWorker.ready;
      return waitForCurrentWorker();
    } catch {
      // The online game remains playable if installation support is unavailable.
      return false;
    }
  })();
})();
