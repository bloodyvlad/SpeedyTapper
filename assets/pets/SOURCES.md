# Pet asset provenance

Runtime pet art is shipped as transparent PNG sprite or two-layer habitat sheets. The six ten-frame sprite sheets are 640×64 PNGs with native 64×64 logical cells. CSS keeps their authored size and frame geometry while using nearest-neighbor sampling whenever a scene scales them. Habitat sheets retain their established 64×48 two-layer contract.

## Generated animal art

The built-in OpenAI image-generation workflow produced the source art used for:

- `foka-sprite.png` and `foka-ice-floe.png`: a tiny white baby seal that always remains prone, with turn, drowsy, and sleeping poses on blue-white ice;
- `kesha-sprite.png` and `kesha-perch.png`: a bright green-yellow parrot with directional turns, a rollover transition, and upside-down sleep on a wooden perch;
- `tauta-sprite.png` and `tauta-bed.png`: a black-white border collie with full and half turns, a half-standing transition, and curled sleep on a cozy brown bed;
- the added intermediate frame in `misha-sprite.png`: a cool neutral-grey tabby matching the user-supplied Misha photo, with only a tiny beige accent, rising between the established sitting and sleeping poses.
- `mitsuri-sprite.png` and `mitsuri-cushion.png`: a bright red rabbit with one pale light-pink right-ear stripe, plus a bubblegum-pink two-layer cushion. Mitsuri's generated ten-frame source was edited to remove its initial pink hair curl while preserving the exact front/left/right/settling/sleeping frame order. The final sprite contains no loose hair thread.

Prompts required isolated orthographic game-sprite poses, crisp deliberate pixel clusters, a fixed center/contact baseline, transparent-friendly flat chroma backgrounds, no text, and separate habitat layers. The accepted high-resolution sources are retained under `sources/chroma/`; the corresponding reviewed transparent intermediates are under `sources/alpha/`. Foka, Kesha, Tauta, and Misha use magenta source backgrounds so Kesha's green plumage is not keyed away. Mitsuri uses green. The obsolete Mitsuri source with a pink hair thread is deliberately not retained.

`python3 scripts/build-pet-sprites.py` (Python 3 plus Pillow) extracts only the authored connected component for each pose, removes residual magenta edge spill, downsamples with nearest-neighbor sampling, and hardens the final runtime matte to binary alpha at the accepted 96 cutoff. The result is ten native 64×64 cells without semi-transparent edge softness. The former accepted runtime sheets are retained under `sources/layout/` only as baseline, footprint, and pose-order templates; they are not used as the detail source for the five generated animals. This keeps all existing animation percentages and CSS scene geometry valid while recovering substantially more of the generated pixel detail. The existing habitat runtime sheets remain unchanged.

Mitsuri used the built-in OpenAI image-generation tool with `foka-sprite.png` and `misha-sprite.png` as style/frame-order references. The final edit prompt required exactly ten equally spaced poses, saturated scarlet fur, no hair ornament, and one much lighter/paler stripe on the rabbit's right ear in every direction. A separate two-cell prompt used the existing floe/bed sheets as layer references for the cushion. The final ten-pose chroma master is byte-for-byte identical to the user-supplied `Generated image 3.png` (`SHA-256 2a735d182ed28a23b867d53315f500bf0c8cfbcf74cb4fc704e67d4efcc86ffa`). Both green-screen sources and their reviewed alpha versions are now protected by Git rather than depending on a temporary image-generation cache.

## Retained generated masters

| Source | Dimensions | Contents |
| --- | ---: | --- |
| `sources/chroma/foka-generated.png` | 1536×1024 | ten seal poses plus two floe layers |
| `sources/chroma/kesha-generated.png` | 1536×1024 | ten parrot poses plus two perch layers |
| `sources/chroma/tauta-generated.png` | 1536×1024 | ten dog poses plus two bed layers |
| `sources/chroma/misha-turn-generated.png` | 1536×1024 | eight directional cat poses |
| `sources/chroma/misha-transition-generated.png` | 1254×1254 | crouching/rising cat transition |
| `sources/chroma/misha-sleep-climber-generated.png` | 1021×1541 | sleeping cat plus climber |
| `sources/chroma/mitsuri-generated.png` | 2172×724 | final ten red-rabbit poses |
| `sources/chroma/mitsuri-cushion-generated.png` | 1774×887 | two cushion layers |
| `sources/chroma/pancake-generated-concept.png` | 1536×1024 | unused generated concept and dance tiles |

## Pancake

`pancake-sprite.png` is not replaced by the generated concept. Its accepted poses were extracted from the user-supplied `Screen Recording 2026-07-13 at 23.58.22.mov`: the upright pose near 0.05 seconds and down/rest pose near 0.30 seconds. The original recording is no longer present in the repository, so `sources/layout/pancake-runtime-32.png` is the best surviving accepted source. The 640×64 runtime sheet is an exact nearest-neighbor 2× preservation of that sheet: it is crisp at the 64px scene size but does not invent detail. Repeated upright/down cells provide the dance; CSS mirrors the same source horizontally for left-facing movement, draws the separate glow line, and overlays two bold dark eye dots for legibility. `sources/chroma/pancake-generated-concept.png` is retained for provenance but is not shipped as Pancake's runtime appearance.

The recording was supplied for this implementation. Independent copyright or redistribution clearance for its online source was not established in the repository; confirm distribution rights before a public production release.
