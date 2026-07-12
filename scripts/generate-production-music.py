#!/usr/bin/env python3
"""Generate the three click-safe production adaptive soundtracks.

These are original procedural compositions. They use no third-party samples and
deliberately avoid noise percussion, hats, vinyl texture, and zero-attack edges.
"""

import argparse
from dataclasses import dataclass
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import wave

import numpy as np


RATE = 48_000
ROOT = Path(__file__).resolve().parents[1]
RUNTIME_OUT = ROOT / "assets" / "audio"
MASTER_OUT = RUNTIME_OUT / "music-masters"
TEMPOS = (100, 120, 140, 168)
SECTION_BOUNDARIES = (0, 460_800, 844_800, 1_173_943, 1_448_229)
CORE_AUDIO_VALID_FRAMES = 1_448_208


@dataclass(frozen=True)
class Track:
    filename: str
    title: str
    root: int
    mode: tuple[int, ...]
    progression: tuple[int, ...]
    bass_pattern: tuple[int, ...]
    character: int


TRACKS = (
    Track(
        "neon-circuit-refined",
        "Neon Circuit Refined",
        38,
        (0, 3, 5, 7, 10, 12),
        (0, -2, 3, -5),
        (0, 0, 7, 3, 0, 10, 7, 3),
        0,
    ),
    Track(
        "deep-current",
        "Deep Current",
        36,
        (0, 2, 3, 7, 10, 12),
        (0, 3, -2, 5),
        (0, 7, 3, 0, 10, 7, 5, 3),
        1,
    ),
    Track(
        "power-grid",
        "Power Grid",
        42,
        (0, 2, 5, 7, 9, 12),
        (0, -5, -2, 3),
        (0, 7, 2, 9, 0, 5, 7, 2),
        2,
    ),
)


def hz(note):
    return 440.0 * 2 ** ((note - 69) / 12)


def stereo(duration):
    return np.zeros((int(round(duration * RATE)), 2), dtype=np.float64)


def smooth_edges(signal, attack=0.006, release=0.035):
    result = signal.copy()
    attack_count = min(len(result), max(1, int(round(attack * RATE))))
    release_count = min(len(result), max(1, int(round(release * RATE))))
    result[:attack_count] *= np.sin(np.linspace(0, np.pi / 2, attack_count)) ** 2
    result[-release_count:] *= np.cos(np.linspace(0, np.pi / 2, release_count)) ** 2
    result[0] = 0
    result[-1] = 0
    return result


def place(bus, sound, when, gain=1.0, pan=0.0):
    start = int(round(when * RATE))
    if start >= len(bus):
        return
    sound = sound[: len(bus) - start]
    if sound.ndim == 1:
        left = np.sqrt((1 - pan) * 0.5)
        right = np.sqrt((1 + pan) * 0.5)
        sound = np.column_stack((sound * left, sound * right))
    bus[start:start + len(sound)] += sound * gain


def soft_clip(signal, drive=1.0):
    return np.tanh(signal * drive) / np.tanh(drive)


def kick(character=0):
    duration = 0.52
    t = np.arange(int(round(duration * RATE))) / RATE
    base = (45, 42, 48)[character]
    sweep = (72, 62, 78)[character]
    frequency = base + sweep * np.exp(-t * 25)
    phase = 2 * np.pi * np.cumsum(frequency) / RATE
    body = np.sin(phase) * np.exp(-t * (8.6, 7.8, 9.2)[character])
    warmth = 0.12 * np.sin(phase * 0.5 + 0.2) * np.exp(-t * 11)
    return smooth_edges(soft_clip(body + warmth, 1.2) * 0.76, 0.005, 0.045)


def round_percussion(note=155, character=0):
    duration = 0.34
    t = np.arange(int(round(duration * RATE))) / RATE
    fundamental = note * (1 + (0.03 + 0.01 * character) * np.exp(-t * 18))
    phase = 2 * np.pi * np.cumsum(fundamental) / RATE
    body = np.sin(phase) + 0.20 * np.sin(phase * 1.5 + 0.35)
    envelope = np.exp(-t * (13 + character))
    return smooth_edges(body * envelope * 0.25, 0.008, 0.050)


def bass(note, duration, character=0, accent=1.0):
    t = np.arange(int(round(duration * RATE))) / RATE
    phase = 2 * np.pi * hz(note) * t
    if character == 0:
        tone = np.sin(phase) + 0.24 * np.sin(2 * phase + 0.18) + 0.06 * np.sin(3 * phase)
    elif character == 1:
        tone = np.sin(phase) + 0.18 * np.sin(1.5 * phase + 0.4) + 0.13 * np.sin(2 * phase)
    else:
        tone = np.sin(phase) + 0.20 * np.sin(2 * phase + 0.6) + 0.08 * np.sin(4 * phase)
    attack = np.minimum(1, t / 0.014)
    release = np.exp(-t / max(0.11, duration * (0.62 + character * 0.06)))
    return smooth_edges(soft_clip(tone * attack * release, 1.12) * 0.30 * accent, 0.006, 0.035)


def muted_voice(note, duration, character=0, accent=1.0):
    t = np.arange(int(round(duration * RATE))) / RATE
    frequency = hz(note)
    phase = 2 * np.pi * frequency * t
    if character == 0:
        tone = np.sin(phase + 0.38 * np.sin(2 * phase) * np.exp(-t * 6))
        tone += 0.14 * np.sin(phase * 0.5)
    elif character == 1:
        tone = np.sin(phase) + 0.20 * np.sin(2 * phase + 0.5) + 0.05 * np.sin(3 * phase)
    else:
        tone = np.sin(phase + 0.18 * np.sin(3 * phase)) + 0.17 * np.sin(phase * 0.5 + 0.8)
    envelope = np.minimum(1, t / 0.018) * np.exp(-t / max(0.10, duration * 0.48))
    return smooth_edges(tone * envelope * 0.17 * accent, 0.008, 0.045)


def pad(notes, duration, character=0, motion=0.0):
    t = np.arange(int(round(duration * RATE))) / RATE
    sound = np.zeros_like(t)
    for index, note in enumerate(notes):
        frequency = hz(note)
        drift = 1 + (index - 1.5) * (0.00055 + 0.00012 * character)
        sound += np.sin(2 * np.pi * frequency * drift * t + index * (0.35 + character * 0.1))
        sound += 0.10 * np.sin(2 * np.pi * frequency * 2 * t + index * 0.7)
    sound /= len(notes)
    fade_in = np.minimum(1, t / (0.65 + 0.12 * character))
    fade_out = np.minimum(1, np.maximum(0, duration - t) / 0.75)
    movement = 0.86 + 0.14 * np.sin(2 * np.pi * (0.075 + motion) * t + character)
    return smooth_edges(sound * fade_in * fade_out * movement * 0.12, 0.020, 0.080)


def chord_for(track, bar_index):
    root = track.root + 12 + track.progression[bar_index % len(track.progression)]
    offsets = (0, 3, 7, 10) if track.character != 2 else (0, 5, 7, 12)
    return tuple(root + offset for offset in offsets)


def render_section(track, bpm, stage, bars=4):
    beat = 60 / bpm
    bar_duration = beat * 4
    duration = bars * bar_duration
    music = stereo(duration)
    drums = stereo(duration)

    for bar_index in range(bars):
        bar_start = bar_index * bar_duration
        chord = chord_for(track, bar_index)
        pad_gain = (0.76, 0.82, 0.94, 0.88)[stage]
        place(music, pad(chord, bar_duration, track.character, stage * 0.012), bar_start,
              gain=pad_gain, pan=(-0.10 if bar_index % 2 else 0.10))
        if stage >= 1:
            low_chord = tuple(note - 12 for note in chord)
            place(music, pad(low_chord, bar_duration, track.character, 0.008), bar_start,
                  gain=0.18 + stage * 0.025, pan=(0.10 if bar_index % 2 else -0.10))

        # Each stage adds pressure; the last jump is reserved for two-minute play.
        if stage == 0:
            kick_beats = (0, 1, 2, 3)
            kick_gain = 0.78
        elif stage == 1:
            kick_beats = (0, 1, 2, 3)
            kick_gain = 0.86
        elif stage == 2:
            kick_beats = (0, 1, 2, 3)
            kick_gain = 0.88
        else:
            kick_beats = (0, 1, 2, 3)
            kick_gain = 0.90
        for beat_index in kick_beats:
            place(drums, kick(track.character), bar_start + beat_index * beat, gain=kick_gain)

        percussion_beats = () if stage == 0 else (1, 3)
        if stage == 2:
            percussion_beats = (1, 2.5, 3)
        for percussion_index, beat_index in enumerate(percussion_beats):
            note = (170, 138, 188)[track.character] * (0.88 if stage == 2 else 1)
            pan = -0.22 if (percussion_index + bar_index) % 2 else 0.22
            place(drums, round_percussion(note, track.character), bar_start + beat_index * beat,
                  gain=0.54 if stage < 3 else 0.68, pan=pan)

        steps = 8
        step_duration = bar_duration / steps
        for step_index in range(steps):
            when = bar_start + step_index * step_duration
            pattern_note = track.root + track.bass_pattern[(step_index + bar_index * 2) % 8]
            if stage == 0:
                bass_active = step_index in (0, 2, 4, 6)
            elif stage == 1:
                bass_active = step_index in (0, 2, 3, 4, 6, 7)
            elif stage == 2:
                bass_active = step_index in (0, 1, 3, 4, 6, 7)
            else:
                bass_active = True
            if bass_active:
                place(music, bass(pattern_note, step_duration * 1.35, track.character,
                                  1.12 if step_index == 0 else 0.94), when, gain=0.82)

            if stage == 0:
                voice_steps = (0, 4)
            elif stage == 1:
                voice_steps = (0, 3, 6)
            elif stage == 2:
                voice_steps = (0, 3, 5, 7)
            else:
                voice_steps = (0, 2, 3, 5, 6)
            if step_index in voice_steps:
                scale_index = (step_index + bar_index * (track.character + 1)) % len(track.mode)
                octave = 24 if stage < 3 else 36
                voice_note = track.root + octave + track.mode[scale_index]
                duration_scale = 1.75 if stage == 2 else 1.55
                place(music, muted_voice(voice_note, step_duration * duration_scale,
                                         track.character, 1.08 if step_index == 0 else 0.84),
                      when, gain=(0.50, 0.62, 0.54, 0.68)[stage],
                      pan=(-0.32 if step_index % 2 else 0.32))

    # Smooth musical ducking leaves low-frequency space without producing clicks.
    duck = np.ones(len(music))
    for bar_index in range(bars):
        bar_start = bar_index * bar_duration
        kick_beats = (0, 1, 2, 3)
        for beat_index in kick_beats:
            start = int(round((bar_start + beat_index * beat) * RATE))
            count = min(int(0.24 * RATE), len(duck) - start)
            if count > 0:
                t = np.arange(count) / RATE
                duck[start:start + count] *= 1 - (0.22 + stage * 0.012) * np.exp(-t * 12)
    music *= duck[:, None]

    mix = music + drums
    dry = mix.copy()
    ambience = (
        (0.113, 0.075, 0),
        (0.151, 0.068, 1),
        (0.247, 0.032, 0),
        (0.293, 0.030, 1),
    )
    for delay, gain, channel in ambience:
        shift = int(round(delay * RATE))
        mix[shift:, channel] += dry[:-shift, 1 - channel] * gain
    return mix


def write_pcm32(path, audio):
    peak = float(np.max(np.abs(audio)))
    if peak > 0:
        audio = audio / max(1.0, peak / 0.94)
    pcm = np.int32(np.clip(audio, -1, 1) * 2_147_483_647)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(4)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i4").tobytes())


def read_pcm16(path):
    with wave.open(str(path), "rb") as source:
        frames = source.readframes(source.getnframes())
    return np.frombuffer(frames, dtype="<i2").reshape(-1, 2).astype(np.float64) / 32_768


def write_pcm16(path, audio):
    pcm = np.int16(np.clip(audio, -1, 1) * 32_767)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i2").tobytes())


def finalize_edges(audio, duration=0.080):
    result = audio.copy()
    count = min(len(result) // 2, max(1, int(round(duration * RATE))))
    result[:count] *= (np.sin(np.linspace(0, np.pi / 2, count)) ** 2)[:, None]
    result[-count:] *= (np.cos(np.linspace(0, np.pi / 2, count)) ** 2)[:, None]
    result[0] = 0
    result[-1] = 0
    return result


def master_section(section, source_path, mastered_path):
    write_pcm32(source_path, section)
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(source_path),
            "-af",
            (
                "highpass=f=25,lowpass=f=9000,highshelf=f=4800:g=-3.5,"
                "acompressor=threshold=0.24:ratio=2.0:attack=16:release=170:makeup=1.10,"
                "volume=1.8dB,alimiter=limit=0.92"
            ),
            "-ar", str(RATE), "-c:a", "pcm_s16le", str(mastered_path),
        ],
        check=True,
    )
    return finalize_edges(read_pcm16(mastered_path))


def render_track(track):
    mastered_sections = []
    with tempfile.TemporaryDirectory(prefix="speedytapper-music-") as temporary:
        temporary_path = Path(temporary)
        for index, bpm in enumerate(TEMPOS):
            section = render_section(track, bpm, index)
            source_path = temporary_path / f"section-{index}-source.wav"
            mastered_path = temporary_path / f"section-{index}-mastered.wav"
            mastered_sections.append(master_section(section, source_path, mastered_path))

    master = np.concatenate(mastered_sections)
    wav_path = MASTER_OUT / f"{track.filename}.wav"
    m4a_path = RUNTIME_OUT / f"{track.filename}.m4a"
    write_pcm16(wav_path, master)
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(wav_path),
            "-map_metadata", "-1",
            "-metadata", f"title={track.title}",
            "-metadata", "artist=SpeedyTapper",
            "-metadata", "comment=Original SpeedyTapper production soundtrack; no third-party samples",
            "-ar", str(RATE), "-c:a", "aac", "-profile:a", "aac_low", "-b:a", "192k",
            str(m4a_path),
        ],
        check=True,
    )
    return mastered_sections, wav_path, m4a_path


def verify_aac(track, sections, m4a_path):
    probe = json.loads(subprocess.check_output(
        [
            "ffprobe", "-v", "error", "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,profile,sample_rate,channels,bit_rate",
            "-of", "json", str(m4a_path),
        ],
        text=True,
    ))["streams"][0]
    if (
        probe.get("codec_name") != "aac"
        or probe.get("profile") != "LC"
        or int(probe.get("sample_rate", 0)) != RATE
        or int(probe.get("channels", 0)) != 2
    ):
        raise RuntimeError(f"Unexpected AAC format for {m4a_path}: {probe}")

    decoded_bytes = subprocess.check_output(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-i", str(m4a_path),
            "-ar", str(RATE), "-ac", "2", "-c:a", "pcm_s16le", "-f", "s16le", "-",
        ]
    )
    decoded = np.frombuffer(decoded_bytes, dtype="<i2").reshape(-1, 2).astype(np.float64) / 32_768
    expected_frames = sum(len(section) for section in sections)
    padding_frames = len(decoded) - expected_frames
    if padding_frames < 0 or padding_frames > 1_023:
        raise RuntimeError(
            f"Decoded duration drift for {m4a_path}: {len(decoded)} vs {expected_frames} frames"
        )
    decoded = decoded[:expected_frames]

    peak = float(np.max(np.abs(decoded)))
    dc = float(np.max(np.abs(np.mean(decoded, axis=0))))
    maximum_step = float(np.max(np.abs(np.diff(decoded, axis=0))))
    if peak >= 0.999:
        raise RuntimeError(f"Decoded AAC clips for {m4a_path}: peak={peak:.5f}")
    if dc > 0.01:
        raise RuntimeError(f"Excessive DC offset for {m4a_path}: dc={dc:.6f}")
    if maximum_step > 0.20:
        raise RuntimeError(f"Impulse-like discontinuity for {m4a_path}: step={maximum_step:.5f}")

    boundary_peaks = []
    boundary = 0
    edge_window = int(round(0.010 * RATE))
    for section in sections[:-1]:
        boundary += len(section)
        window = decoded[max(0, boundary - edge_window):boundary + edge_window]
        boundary_peaks.append(float(np.max(np.abs(window))))
    if any(value > 0.08 for value in boundary_peaks):
        raise RuntimeError(f"Noisy AAC section boundary for {m4a_path}: {boundary_peaks}")

    afinfo_path = shutil.which("afinfo")
    if afinfo_path:
        core_audio_info = subprocess.check_output([afinfo_path, str(m4a_path)], text=True)
        valid_frames_match = re.search(r"audio\s+(\d+)\s+valid frames", core_audio_info)
        if not valid_frames_match:
            raise RuntimeError(f"Unable to read CoreAudio valid frames for {m4a_path}")
        valid_frames = int(valid_frames_match.group(1))
        if valid_frames != CORE_AUDIO_VALID_FRAMES:
            raise RuntimeError(
                f"Unexpected CoreAudio duration for {m4a_path}: "
                f"{valid_frames} vs {CORE_AUDIO_VALID_FRAMES} valid frames"
            )

    print(
        f"QA {track.title}: AAC-{probe['profile']} {probe['sample_rate']} Hz, "
        f"{int(probe.get('bit_rate', 0)) // 1000} kbps, peak {peak:.4f}, "
        f"DC {dc:.6f}, max-step {maximum_step:.4f}, "
        f"boundary-peaks {','.join(f'{value:.4f}' for value in boundary_peaks)}, "
        f"tail-padding {padding_frames} frames"
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--verify-only",
        action="store_true",
        help="Verify the retained masters and runtime AACs without regenerating them.",
    )
    arguments = parser.parse_args()
    MASTER_OUT.mkdir(parents=True, exist_ok=True)
    for track in TRACKS:
        if arguments.verify_only:
            master = read_pcm16(MASTER_OUT / f"{track.filename}.wav")
            sections = [
                master[start:end]
                for start, end in zip(SECTION_BOUNDARIES, SECTION_BOUNDARIES[1:])
            ]
            m4a_path = RUNTIME_OUT / f"{track.filename}.m4a"
        else:
            sections, _, m4a_path = render_track(track)
        verify_aac(track, sections, m4a_path)


if __name__ == "__main__":
    main()
