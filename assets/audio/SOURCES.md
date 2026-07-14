# Audio sources

All current audio is original SpeedyTapper material. It contains no third-party samples and has no external royalty requirement. The requested downtempo reference informed only broad qualities such as restraint, warmth, and space; no Moby recording, melody, arrangement, or other protected audio was sampled or imitated.

## Sound FX

- `tap-tones.wav` is an original 48 kHz/16-bit mono PCM bank. It is the former Deep Current uniform note bank introduced by commit `de170ab` and renamed without changing its bytes. SHA-256: `d892f4f4d8c884ed3001f81741ede2520eb95cf2209d01cc6a020407e0394d70`.
- The bank contains a fixed 16-note motif in sixteen 500 ms slots. Every slot uses the same 20,160-frame (420 ms) envelope followed by an exact 3,840-frame (80 ms) zero tail, exact-zero boundaries, and RMS-normalized energy. Runtime playback advances only after a correct tap and stays at native 1× speed.
- Deep Current was selected from three available uniform banks because it had the purest harmonic concentration (`99.9978%`) and gentlest maximum sample transition (`0.06653`) while retaining `3.0 dBFS` peak headroom.
- `oops.wav` is the original synthesized 620 ms life-loss cue restored byte-for-byte from commit `7d4b0d6427892af08ae77ece62734294c79d22be`. The 48 kHz/16-bit mono PCM file is both runtime asset and retained lossless master. SHA-256: `d8c80dc7962a92d504aa51dc8c383fffd8279fc83a4ef90cd288c10a66cebb31`.

## Background music

- `background-deep-current.m4a` is the single shipped Music runtime. It is a 9.600-second, 100 BPM AAC-LC loop without a time-driven lead or tap motif. Runtime gain is deliberately low so the Sound FX tones remain foreground feedback. SHA-256: `2553891582533f43122528754d24cf603ff8bc6cb406fb8ee5194903c0af998f`.
- `background-masters/deep-current.wav` is its retained 48 kHz/16-bit stereo PCM master. It was cropped without resynthesis from frames 4,096 through 464,895 of the original Deep Current Interactive backing master at commit `7d4b0d6427892af08ae77ece62734294c79d22be`. That exact 460,800-frame `opening` island was authored as a seamless backing-only loop. SHA-256: `e8c82b5285c8a6eca108f0e436c9151b2f439b7182d2dc5c59c2361bf91b5e70`.
- The runtime file was encoded from that cropped master at 48 kHz AAC-LC, 160 kbps, with metadata identifying it as original SpeedyTapper audio. The decoded runtime reports exactly 460,800 valid frames.

The former hum, adaptive soundtrack stages, Interactive Music backings, alternative note banks, generators, and other music masters remain removed from the current tree. Their complete prior versions remain recoverable from Git commit `7d4b0d6427892af08ae77ece62734294c79d22be`.

Automated measurements do not replace physical-iPhone Safari and installed-PWA listening for tap latency, failure-cue level, loop seam, bass audibility, and mix balance.
