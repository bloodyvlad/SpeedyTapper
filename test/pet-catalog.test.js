import assert from "node:assert/strict";
import test from "node:test";

import { getPet, normalizeOwnedPetIds, PET_CATALOG } from "../src/pet-catalog.js";

test("the pet catalog keeps stable ids, names, prices, and order", () => {
  assert.deepEqual(
    PET_CATALOG.map(({ id, name, priceCoins }) => ({ id, name, priceCoins })),
    [
      { id: "foka", name: "Foka", priceCoins: 10 },
      { id: "kesha", name: "Kesha", priceCoins: 20 },
      { id: "tauta", name: "Tauta", priceCoins: 50 },
      { id: "misha", name: "Misha", priceCoins: 100 },
      { id: "pancake", name: "Pancake", priceCoins: 500 }
    ]
  );
  assert.equal(getPet("pancake").home, "Side-view glow line");
});

test("owned pet ids are deduplicated and unknown values are ignored", () => {
  assert.deepEqual(normalizeOwnedPetIds(["misha", "unknown", "misha", "foka"]), ["misha", "foka"]);
  assert.deepEqual(normalizeOwnedPetIds(null), []);
});
