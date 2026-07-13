export const PET_CATALOG = Object.freeze([
  Object.freeze({
    id: "foka",
    name: "Foka",
    priceCoins: 10,
    home: "Ice floe",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "kesha",
    name: "Kesha",
    priceCoins: 20,
    home: "Perch",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "tauta",
    name: "Tauta",
    priceCoins: 50,
    home: "Cozy brown bed",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "misha",
    name: "Misha",
    priceCoins: 100,
    home: "Climber",
    idlePose: "sleeping"
  }),
  Object.freeze({
    id: "pancake",
    name: "Pancake",
    priceCoins: 500,
    home: "Side-view glow line",
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
