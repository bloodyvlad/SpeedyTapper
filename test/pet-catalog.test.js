import assert from "node:assert/strict";
import test from "node:test";

import {
  getPet,
  isShopPetId,
  isSpecialPetId,
  MITSURI_PET,
  MUSE_PET,
  normalizeOwnedPetIds,
  PET_CATALOG,
  resolvePetShopAction
} from "../src/pet-catalog.js";

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
  assert.equal(getPet("pancake").kind, "Dancing meme");
  assert.equal(getPet("mitsuri"), MITSURI_PET);
  assert.equal(MITSURI_PET.kind, "Red rabbit");
  assert.equal(getPet("muse"), MUSE_PET);
  assert.equal(MUSE_PET.kind, "Home companion");
  assert.equal(isSpecialPetId("mitsuri"), true);
  assert.equal(isSpecialPetId("muse"), true);
  assert.equal(isShopPetId("mitsuri"), false);
  assert.equal(isShopPetId("muse"), false);
});

test("owned pet ids are deduplicated and unknown values are ignored", () => {
  assert.deepEqual(normalizeOwnedPetIds(["misha", "unknown", "misha", "foka"]), ["misha", "foka"]);
  assert.deepEqual(normalizeOwnedPetIds(["mitsuri", "muse", "foka"]), ["foka"]);
  assert.deepEqual(normalizeOwnedPetIds(null), []);
});

test("shop actions distinguish buying, selecting, hiding, and showing", () => {
  assert.equal(resolvePetShopAction(), "Buy");
  assert.equal(resolvePetShopAction({ owned: true }), "Select");
  assert.equal(resolvePetShopAction({ owned: true, selected: true, visible: true }), "Hide");
  assert.equal(resolvePetShopAction({ owned: true, selected: true, visible: false }), "Show");
});
