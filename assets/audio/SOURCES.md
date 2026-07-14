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

## Theme-specific suites

Disco, Light, and Pixel each ship one original theme-owned audio suite: a clean gameplay background, a matching menu version with a sixteen-note motif, and a click-safe tap-tone bank derived from the same musical identity. `scripts/generate-theme-audio.py` deterministically synthesizes every source with NumPy and FFmpeg; it uses no samples, recordings, or external musical material. Each background and menu runtime is a 12.000-second 48 kHz stereo AAC-LC file encoded at 160 kbps. Each tap bank is an 8.000-second 48 kHz/16-bit mono PCM file containing sixteen 500 ms slots, with 420 ms of equal-energy sound followed by an exact 80 ms zero tail.

| Theme | Original identity | Tempo | Runtime measurements | Runtime SHA-256 (background / menu / tones) |
| --- | --- | ---: | --- | --- |
| Disco | **Mirror Circuit** | 120 BPM | background `-18.18 LUFS`, `-4.21 dBTP`; menu `-18.39 LUFS`, `-6.68 dBTP` | `d811692605582c5e4f1009a7f16fde1f2aeedb3b21ac4c693abe145abe9e91c4` / `da77a7b82e26989ee04de8aadaab188d90f866fd32ee80ad3507335e9c671ea0` / `e5727ef9aaa53887f4d90ee5bd4d65c9a8a0250d5db38855c908b1418fc4c6db` |
| Light | **Open Sky** | 100 BPM | background `-18.06 LUFS`, `-5.60 dBTP`; menu `-18.15 LUFS`, `-6.75 dBTP` | `bbd570a178c0bd7e8d01fb424350a8bdb8da486e1e899af07ea7289895cd5360` / `7c4edae3506caa5420905af1042ed0e1f056c48ed4b3812f9936aa8308ae6a1b` / `2cf853df10af1127b8e08dbc6f1bb83858dbe8aa1ff8be842ca9e1dd17d25405` |
| Pixel | **Coin-Op Spark** | 160 BPM | background `-18.32 LUFS`, `-4.40 dBTP`; menu `-18.55 LUFS`, `-6.66 dBTP` | `852ccc3baf7485e26be337efe936de7a20c175eefaa4dfa655afb1856a4e73d5` / `ba83ea167d7d14c9c971acc4892b034ccfc9795d2e32406da20a53f887c5520b` / `c24bbce2ed562ed2878c646b2f08098ecf3736b6a34a34690a9cb4a250065939` |

The runtime paths are `assets/audio/themes/<theme>/background.m4a`, `menu.m4a`, and `tap-tones.wav`. Their retained 48 kHz/16-bit stereo masters and hashes are:

- `background-masters/disco-mirror-circuit.wav`: `8bb5d99ea642c795c0ec7eac966c44a69dbaa86aa0b046c9c71bd8e5ffd9935b`; menu master: `2b232d90e542d3021559da52f22d421f4658cc231f515d0de6ba2e77f4fa2d7f`.
- `background-masters/light-open-sky.wav`: `bb91989662473dcae4d257231b606e6f6d41384f6debaa4616c3ccc7dbd5d485`; menu master: `b0e91aa0c01acb32480d69d8c44f515dd5ad7d26810d8844735b3dd698ef8d`.
- `background-masters/pixel-coin-op-spark.wav`: `0e176913a78f81f871fd49b7f3f0b323dd4a28fb2aaa8896dbe474d60242ef6b`; menu master: `6f423c32d39596d16351c82a663c6cbcc5e3df83039477f6abe8bcbd22e7d97a`.

Every new loop fades only its first and last 5 ms to exact zero before AAC encoding. Measured decoded seam deltas over the authored first 576,000 frames are `0.003205/0.001737` for Disco background/menu, `0.001958/0.003136` for Light, and `0.001538/0.002077` for Pixel, all below the generator's `0.016` ceiling. The menu masters reserve additional sample and inter-sample headroom for AAC decoding on iPhone. Runtime controllers load only the selected theme's suite, keep audio outside the install-time service-worker shell, preserve already-unlocked contexts across theme changes, and retain the shared `oops.wav` life-loss cue across themes.

The former hum, adaptive soundtrack stages, Interactive Music backings, pace-dependent variants, and superseded experimental note banks remain removed from the current runtime. Their complete prior versions remain recoverable from Git commit `7d4b0d6427892af08ae77ece62734294c79d22be`.

Automated measurements do not replace physical-iPhone Safari and installed-PWA listening for tap latency, failure-cue level, loop seam, bass audibility, and mix balance.
