const MAX_TRUSTED_EVENT_AGE_MS = 60_000;
const EVENT_FUTURE_TOLERANCE_MS = 1;

export function resolveInputTimestamp(eventTimestamp, currentTime) {
  const fallback = Number.isFinite(currentTime) ? Math.max(0, currentTime) : 0;
  if (!Number.isFinite(eventTimestamp) || eventTimestamp <= 0) return fallback;
  if (eventTimestamp > fallback + EVENT_FUTURE_TOLERANCE_MS) return fallback;
  if (fallback - eventTimestamp > MAX_TRUSTED_EVENT_AGE_MS) return fallback;
  return Math.min(eventTimestamp, fallback);
}

export function reactionDeadline(visibleAt, responseWindowMs) {
  return visibleAt + Math.max(0, responseWindowMs);
}

export function remainingUntilDeadline(deadlineAt, currentTime) {
  return Math.max(0, Math.ceil(deadlineAt - currentTime));
}

export function reachedDeadline(inputAt, deadlineAt) {
  return Number.isFinite(deadlineAt) && inputAt >= deadlineAt;
}

export function predatesPresentation(inputAt, visibleAt) {
  return Number.isFinite(visibleAt) && inputAt < visibleAt;
}

export function wasCoveredByDeadlineResolution(inputAt, resolvedAt) {
  return Number.isFinite(resolvedAt) && inputAt <= resolvedAt;
}

export function scheduleAfterPaint({ requestFrame, cancelFrame }, callback) {
  let cancelled = false;
  let frameId = null;
  frameId = requestFrame(() => {
    if (cancelled) return;
    frameId = requestFrame(() => {
      frameId = null;
      if (!cancelled) callback();
    });
  });

  return Object.freeze({
    cancel() {
      cancelled = true;
      if (frameId !== null) cancelFrame(frameId);
      frameId = null;
    }
  });
}
