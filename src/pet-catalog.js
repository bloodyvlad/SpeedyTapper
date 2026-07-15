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

export const MITSURI_PET = Object.freeze({
  id: "mitsuri",
  name: "Mitsuri",
  kind: "Red rabbit",
  idlePose: "sleeping",
  nicknameOnly: true
});

export const MUSE_PET = Object.freeze({
  id: "muse",
  name: "Muse",
  kind: "Home companion",
  idlePose: "sleeping",
  nicknameOnly: true
});

const SHOP_PETS_BY_ID = new Map(PET_CATALOG.map((pet) => [pet.id, pet]));
const SPECIAL_PETS = Object.freeze([MITSURI_PET, MUSE_PET]);
const SPECIAL_PETS_BY_ID = new Map(SPECIAL_PETS.map((pet) => [pet.id, pet]));
const PETS_BY_ID = new Map([...SHOP_PETS_BY_ID, ...SPECIAL_PETS_BY_ID]);

export function getPet(petId) {
  return typeof petId === "string" ? PETS_BY_ID.get(petId) ?? null : null;
}

export function isPetId(petId) {
  return getPet(petId) !== null;
}

export function isShopPetId(petId) {
  return typeof petId === "string" && SHOP_PETS_BY_ID.has(petId);
}

export function isSpecialPetId(petId) {
  return typeof petId === "string" && SPECIAL_PETS_BY_ID.has(petId);
}

export function normalizeOwnedPetIds(value) {
  if (!Array.isArray(value)) return Object.freeze([]);
  return Object.freeze([...new Set(value.filter(isShopPetId))]);
}

export function resolvePetShopAction({ owned = false, selected = false, visible = false } = {}) {
  if (!owned) return "Buy";
  if (!selected) return "Select";
  return visible ? "Hide" : "Show";
}
