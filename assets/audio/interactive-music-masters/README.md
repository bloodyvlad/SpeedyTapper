# Interactive Music masters

These three original 48 kHz/16-bit stereo PCM files are the retained lossless masters for the optional Interactive Music backing. The approved legacy adaptive soundtrack and its masters remain unchanged.

Each master is one audio sprite containing twelve four-bar loops and authored bridge beats between every adjacent state in both directions. All islands are separated by AAC guard frames. The bridges share the same key, modal harmony, synthesis voices, and beat boundary as their neighbors; the controller schedules them on the next beat before entering the destination loop. This makes grid- and difficulty-driven changes musically aligned while keeping the correct-tap note immediate.

`manifest.json` is the cue-frame source of truth. It records loop and bridge offsets, durations, beat lengths, fixed per-track motifs, and Apple/CoreAudio valid-frame counts. Runtime AAC and PCM note-bank files live in the parent `assets/audio/` directory.

Run `python3 scripts/generate-interactive-music.py --verify-only` to validate the retained files without regenerating them.
