# Adaptive music preview

`01-neon-circuit-clicksafe-v4.wav` is an original procedural composition created for SpeedyTapper. It may be used, modified, and distributed with the game without third-party attribution or royalties.

The rollback master is lossless 48 kHz/16-bit PCM. Every generated one-shot and note has an exact-zero or raised-cosine boundary. Each adaptive section is mastered independently and receives a final 60 ms raised-cosine edge treatment after mastering, so the runtime loop points remain quiet. Noise-based clicks, short hats, and snap samples have been removed.

| Time | Demonstrated state | Tempo |
| --- | --- | --- |
| 0:00 | Menu and 1x1 warm-up | 72 BPM |
| 0:13.33 | 2x2 | 88 BPM |
| 0:24.24 | 4x4 reset | 96 BPM |
| 0:34.24 | 4x4 challenge | 168 BPM |

Files:

- `01-neon-circuit-clicksafe-v4.m4a`: 48 kHz stereo AAC-LC, 192 kbps target; byte-identical retained copy of the runtime soundtrack.
- `01-neon-circuit-clicksafe-v4.wav`: 48 kHz/16-bit stereo PCM rollback master.

The runtime copy is `../neon-circuit-v1.m4a`. The browser decodes it once and uses the section boundaries above as adaptive loop regions. `scripts/generate-neon-hifi-preview.py` regenerates the PCM master, retained AAC copy, and runtime AAC from the same source.
