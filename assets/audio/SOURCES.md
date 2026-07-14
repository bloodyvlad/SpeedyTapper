# Audio sources

All current audio is original SpeedyTapper material. It contains no third-party samples and has no external royalty requirement. The requested downtempo reference informed only broad qualities such as restraint, warmth, and space; no Moby recording, melody, arrangement, or other protected audio was sampled or imitated.

## Sound FX

- `tap-tones.wav` is an original 48 kHz/16-bit mono PCM bank. It is the former Power Grid uniform note bank introduced by commit `de170ab` and renamed without changing its bytes. SHA-256: `f880c81515b731c867c8f50bae64187dce0af00930c2559a692d52e36b96764c`.
- The bank contains a fixed 16-note motif in sixteen 500 ms slots. Every slot uses the same 20,160-frame (420 ms) envelope followed by an exact 3,840-frame (80 ms) zero tail, exact-zero boundaries, and RMS-normalized energy. Runtime playback advances only after a correct tap and stays at native 1× speed.
- Power Grid was selected after direct listening preference. Its fixed F-sharp-major/pentatonic motif has active-slot RMS `0.229982`, peak `0.754211`, and maximum adjacent sample transition `0.099121`, while preserving the same click-safe half-second slot layout as the previous bank.
- `oops.wav` is the original synthesized 620 ms life-loss cue restored byte-for-byte from commit `7d4b0d6427892af08ae77ece62734294c79d22be`. The 48 kHz/16-bit mono PCM file is both runtime asset and retained lossless master. SHA-256: `d8c80dc7962a92d504aa51dc8c383fffd8279fc83a4ef90cd288c10a66cebb31`.
- Runtime mixing uses the same `0.375` base gain for tap tones and the life-loss cue. The persistent Sound FX slider scales their shared output from 0–100% after that internal balance; changing it does not regenerate or alter either master.

## Background music

- `background-daylight-circuit.m4a` is the clean gameplay Music runtime. It is an original 12.000-second, 80 BPM AAC-LC loop in F-sharp major, built from warm pads, restrained mid-bass, soft half-time tonal percussion, and quiet chord blooms. It has no lead melody, time-driven motif, third-party sample, or noise percussion. Runtime mixing uses a `0.42` base gain, 50% above the preceding `0.28` setting; the independent persistent Music slider scales that base from 0–100% without changing the encoded file. SHA-256: `bf8431818ac484d10679090a62098e3dc426487981c2e4d9e2bc9dc6134401b7`.
- `background-daylight-circuit-menu.m4a` is the matching menu runtime. It overlays the sixteen approved Power Grid notes across the same sixteen 80 BPM beat positions at a restrained `0.20` mix gain and subtle alternating stereo positions. Those notes belong to Music in the menu mix; gameplay uses the clean runtime and plays Power Grid notes only for correct taps through Sound FX. The 48 kHz AAC-LC runtime is exactly 12.000 seconds. SHA-256: `2780f58ed60af07443d0a216d7ec6942b321c957bfa167743fa7ddf62d10774b`.
- `background-masters/daylight-circuit.wav` is the retained 48 kHz/16-bit stereo clean master. SHA-256: `89182fd3b2e994c1b4fe0396b8178d96d8e3b45267e9741412a06a3f3d35d948`. `scripts/generate-light-background.py` deterministically reproduces the clean master and runtime without external samples or randomness.
- `background-masters/daylight-circuit-menu.wav` is the retained 48 kHz/16-bit stereo menu master. SHA-256: `bee6465aac2573c868052cea083ede4c4531396c953568a9bd67cf58aaef64a6`. `scripts/generate-menu-background.py` deterministically combines the retained clean master and approved tap-tone bank, then reproduces the menu runtime without external material or randomness.
- The runtime file is encoded from that master at 48 kHz AAC-LC, 160 kbps, with metadata identifying it as original SpeedyTapper audio. It measures approximately `-18.3 LUFS`, peaks at `-7.3 dBTP`, and decodes to exactly 12.000 seconds for the authored loop boundary. The lossless seam jump is `0.00461`; the decoded AAC boundary remains below `0.016`.
- `background-masters/deep-current.wav` is the retained lossless rollback master for the superseded 9.600-second Deep Current runtime. Its runtime remains recoverable from release commit `8c52005bfa4a19032c2a1d27d15a90baee9ac18e`.

The former hum, adaptive soundtrack stages, Interactive Music backings, alternative runtime note banks, and other music masters remain removed from the current tree. Their complete prior versions remain recoverable from Git commit `7d4b0d6427892af08ae77ece62734294c79d22be`.

Automated measurements do not replace physical-iPhone Safari and installed-PWA listening for tap latency, failure-cue level, loop seam, bass audibility, and mix balance.
