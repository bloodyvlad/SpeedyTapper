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

- `01-neon-circuit-clicksafe-v4.m4a`: 48 kHz stereo AAC-LC, 192 kbps target; retained copy of the previous runtime soundtrack.
- `01-neon-circuit-clicksafe-v4.wav`: 48 kHz/16-bit stereo PCM rollback master.

The previous runtime copy is retained at `../neon-circuit-v1.m4a` for rollback. `scripts/generate-neon-hifi-preview.py` regenerates that PCM master and AAC copy from the same source.
