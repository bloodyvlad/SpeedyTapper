# Production adaptive music masters

These three original procedural soundtracks contain no third-party samples. Their runtime AAC-LC copies are stored in the parent `assets/audio/` directory; these WAV files are the retained rollback and regeneration masters.

All candidates use the same adaptive structure:

| Runtime offset | Intended game state | Tempo |
| --- | --- | --- |
| 0:00 | Menu and 1x1 opening | 100 BPM |
| 0:09.60 | Richer 2x2 arrangement | 120 BPM |
| 0:17.60 | Mature 4x4 decoy pressure from 1:30 game time | 140 BPM |
| 0:24.46 | Endurance tier from 2:00 game time | 168 BPM |

The runtime M4A files are 48 kHz stereo AAC-LC with a 192 kbps target. These retained WAV files are 48 kHz/16-bit stereo PCM. Every synthesized voice uses smooth attack/release ramps; sections are mastered independently and receive a final 80 ms raised-cosine edge treatment. The arrangements contain no noise percussion, hats, vinyl texture, or third-party samples.

- **Neon Circuit Refined:** warm analog bass, rounded four-on-floor pulse, and muted neon plucks.
- **Deep Current:** spacious dub-techno harmony, deeper bass movement, and low-mid percussion.
- **Power Grid:** restrained industrial electro, tonal percussion, and an evolving low ostinato.

`scripts/generate-production-music.py` reproduces the three WAV masters and three runtime AAC files. It also decodes and checks each AAC for format, duration, clipping, DC offset, impulse-like discontinuities, and noisy section boundaries. Run it with `--verify-only` to check the retained files without re-encoding the approved AACs.
