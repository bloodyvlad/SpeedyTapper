export const PET_CATALOG = Object.freeze([
  Object.freeze({
    id: "foka",
    name: "Foka",
    priceCoins: 10,
    kind: "Baby seal",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "kesha",
    name: "Kesha",
    priceCoins: 20,
    kind: "Green-yellow parrot",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "tauta",
    name: "Tauta",
    priceCoins: 50,
    kind: "Border collie",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "misha",
    name: "Misha",
    priceCoins: 100,
    kind: "Grey cat",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "pancake",
    name: "Pancake",
    priceCoins: 500,
    kind: "Dancing meme",
    idlePose: "stopped"
  })
]);

const PETS_BY_ID = new Map(PET_CATALOG.map((pet) => [pet.id, pet]));

export function getPet(petId) {
  return typeof petId === "string" ? PETS_BY_ID.get(petId) ?? null : null;
}

export function isPetId(petId) {
  return getPet(petId) !== null;
}

export function normalizeOwnedPetIds(value) {
  if (!Array.isArray(value)) return Object.freeze([]);
  return Object.freeze([...new Set(value.filter(isPetId))]);
}

export function resolvePetShopAction({ owned = false, selected = false, visible = false } = {}) {
  if (!owned) return "Buy";
  if (!selected) return "Select";
  return visible ? "Hide" : "Show";
}
