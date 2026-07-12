#!/usr/bin/env python3
"""Generate the click-safe, lossless SpeedyTapper life-loss cue."""

from pathlib import Path
import wave

import numpy as np


RATE = 48_000
DURATION = 0.62
OUTPUT = Path(__file__).resolve().parents[1] / "assets" / "audio" / "oops.wav"


def main():
    count = int(DURATION * RATE)
    t = np.arange(count) / RATE
    signal = (
        0.50 * np.sin(2 * np.pi * (310 * t - 95 * t * t))
        + 0.24 * np.sin(2 * np.pi * (205 * t - 55 * t * t))
    )

    attack_count = int(0.012 * RATE)
    release_start = int(0.36 * RATE)
    envelope = np.ones(count)
    envelope[:attack_count] = np.sin(np.linspace(0, np.pi / 2, attack_count)) ** 2
    envelope[release_start:] = np.cos(
        np.linspace(0, np.pi / 2, count - release_start)
    ) ** 2
    signal *= envelope * 0.72
    signal[0] = 0
    signal[-1] = 0

    values = np.int16(np.clip(signal, -1, 1) * 32_767)

    with wave.open(str(OUTPUT), "wb") as output:
        output.setnchannels(1)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(values.tobytes())


if __name__ == "__main__":
    main()
