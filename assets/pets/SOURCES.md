# Pet asset provenance

Runtime pet art is shipped as transparent PNG sprite or two-layer habitat sheets. CSS renders the logical 32×32 pet cells with nearest-neighbor pixel sampling.

## Generated animal art

The built-in OpenAI image-generation workflow produced the source art used for:

- `foka-sprite.png` and `foka-ice-floe.png`: a tiny white baby seal that always remains prone, with turn, drowsy, and sleeping poses on blue-white ice;
- `kesha-sprite.png` and `kesha-perch.png`: a bright green-yellow parrot with directional turns, a rollover transition, and upside-down sleep on a wooden perch;
- `tauta-sprite.png` and `tauta-bed.png`: a black-white border collie with full and half turns, a half-standing transition, and curled sleep on a cozy brown bed;
- the added intermediate frame in `misha-sprite.png`: a cool neutral-grey tabby matching the user-supplied Misha photo, with only a tiny beige accent, rising between the established sitting and sleeping poses.
- `mitsuri-sprite.png` and `mitsuri-cushion.png`: a bright red rabbit with one pale light-pink right-ear stripe, plus a bubblegum-pink two-layer cushion. Mitsuri's generated ten-frame source was edited to remove its initial pink hair curl while preserving the exact front/left/right/settling/sleeping frame order. The final sprite contains no loose hair thread.

Prompts required isolated, orthographic 32×32 game-sprite poses; crisp deliberate pixel clusters; a fixed center/contact baseline; transparent-friendly flat chroma backgrounds; no text; and separate habitat layers. Generated sources were chroma-keyed, reduced with nearest-neighbor sampling, and assembled into ten-cell 320×32 pet sheets or two-cell 64×48 habitat sheets. The existing `misha-climber.png` was retained.

Mitsuri used the built-in OpenAI image-generation tool with `foka-sprite.png` and `misha-sprite.png` as style/frame-order references. The final edit prompt required exactly ten equally spaced poses, saturated scarlet fur, no hair ornament, and one much lighter/paler stripe on the rabbit's right ear in every direction. A separate two-cell prompt used the existing floe/bed sheets as layer references for the cushion. Both sources were generated over a flat green chroma key, locally keyed, scrubbed of residual green, reduced with nearest-neighbor sampling, and validated as alpha PNGs at the established runtime dimensions.

## Pancake

`pancake-sprite.png` is not an image-generation reinterpretation. Its source poses were extracted from the user-supplied `Screen Recording 2026-07-13 at 23.58.22.mov`: the upright pose near 0.05 seconds and down/rest pose near 0.30 seconds. Background removal and nearest-neighbor reduction preserve the recording's pancake body, black face, arms, and legs. Repeated upright/down cells provide the dance; CSS mirrors the same source horizontally for left-facing movement, draws the separate two-pixel glow line, and overlays two bold dark eye dots for legibility in the shipped 64px scenes and 32px leaderboard portraits. The PNG source sheet remains unchanged.

The recording was supplied for this implementation. Independent copyright or redistribution clearance for its online source was not established in the repository; confirm distribution rights before a public production release.
