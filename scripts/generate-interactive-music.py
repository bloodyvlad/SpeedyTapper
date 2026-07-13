#!/usr/bin/env python3
"""Generate backing-only Interactive Music assets and tap-note banks.

The approved adaptive soundtrack remains untouched. This renderer reuses its
original synthesis voices, removes the time-driven lead, freezes one canonical
16-note motif per track, and packages all backing loops and bridge beats into a
single AAC sprite per track.
"""

import argparse
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import wave

import numpy as np


RATE = 48_000
ROOT = Path(__file__).resolve().parents[1]
RUNTIME_OUT = ROOT / "assets" / "audio"
MASTER_OUT = RUNTIME_OUT / "interactive-music-masters"
MANIFEST_PATH = MASTER_OUT / "manifest.json"
GUARD_FRAMES = 4_096
NOTE_SLOT_FRAMES = 24_000
NOTE_SOUND_FRAMES = 20_160
NOTE_ATTACK_FRAMES = 576
NOTE_RELEASE_FRAMES = 4_320
NOTE_RMS_TARGET = 0.23
NOTE_PEAK_LIMIT = 0.78

SECTION_SPECS = (
    ("opening", 100, 0),
    ("grid-2", 104, 1),
    ("grid-2-ramp", 108, 2),
    ("grid-2-late", 112, 2),
    ("grid-4", 112, 3),
    ("challenge", 120, 4),
    ("challenge-1", 124, 4),
    ("challenge-2", 128, 5),
    ("challenge-3", 136, 5),
    ("challenge-4", 144, 6),
    ("challenge-5", 156, 6),
    ("endurance", 168, 7),
)

MOTIFS = {
    "neon-circuit-refined": (0, 3, 5, 1, 1, 4, 0, 2, 2, 5, 1, 3, 3, 0, 2, 4),
    "deep-current": (0, 3, 5, 1, 2, 5, 1, 3, 4, 1, 3, 5, 0, 3, 5, 1),
    "power-grid": (0, 3, 5, 1, 3, 0, 2, 4, 0, 3, 5, 1, 3, 0, 2, 4),
}


def load_production_renderer():
    path = ROOT / "scripts" / "generate-production-music.py"
    spec = importlib.util.spec_from_file_location("speedytapper_production_music", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


PRODUCTION = load_production_renderer()


def note_scale_degree_count(track):
    if len(track.mode) < 2 or track.mode[-1] != 12:
        raise RuntimeError(f"{track.filename} mode must end with its repeated octave")
    return len(track.mode) - 1


def note_offsets(track):
    return tuple(
        track.mode[scale_degree]
        for scale_degree in MOTIFS[track.filename]
    )


def note_bank_filename(track):
    return f"interactive-notes-uniform-{track.filename}.wav"


def beat_frames(bpm):
    return int(round(RATE * 60 / bpm))


def exact_zero_edges(audio, frames=384):
    """Apply short raised-cosine edges and force exact PCM-zero endpoints."""
    result = audio.copy()
    count = min(frames, len(result) // 2)
    if count > 0:
        fade_in = np.sin(np.linspace(0, np.pi / 2, count)) ** 2
        fade_out = np.cos(np.linspace(0, np.pi / 2, count)) ** 2
        if result.ndim == 2:
            result[:count] *= fade_in[:, None]
            result[-count:] *= fade_out[:, None]
        else:
            result[:count] *= fade_in
            result[-count:] *= fade_out
    result[0] = 0
    result[-1] = 0
    return result


def pedal_pad(track, duration, richness, beat_index):
    frames = int(round(duration * RATE))
    t = np.arange(frames) / RATE
    root = track.root + 12
    notes = (root, root + 7, root + 12)
    tone = np.zeros(frames)
    for index, note in enumerate(notes):
        frequency = PRODUCTION.hz(note)
        detune = 1 + (index - 1) * (0.00038 + track.character * 0.00008)
        phase = beat_index * (0.31 + index * 0.17)
        tone += np.sin(2 * np.pi * frequency * detune * t + phase)
        tone += 0.08 * np.sin(2 * np.pi * frequency * 2 * t + phase * 1.7)
    tone /= len(notes)
    attack = np.minimum(1, t / 0.035)
    release = np.minimum(1, np.maximum(0, duration - t) / 0.055)
    motion = 0.88 + 0.12 * np.sin(2 * np.pi * (0.22 + richness * 0.01) * t)
    return exact_zero_edges(tone * attack * release * motion * (0.075 + richness * 0.004))


def sub_pulse(track, duration, accent=1.0):
    frames = int(round(duration * RATE))
    t = np.arange(frames) / RATE
    phase = 2 * np.pi * PRODUCTION.hz(track.root - 12) * t
    tone = np.sin(phase) + 0.12 * np.sin(2 * phase + 0.4)
    envelope = np.minimum(1, t / 0.014) * np.exp(-t / max(0.08, duration * 0.55))
    return exact_zero_edges(tone * envelope * 0.22 * accent, frames=240)


def place(bus, sound, frame, gain=1.0, pan=0.0):
    if frame >= len(bus):
        return
    sound = sound[: len(bus) - frame]
    if sound.ndim == 1:
        left = np.sqrt((1 - pan) * 0.5)
        right = np.sqrt((1 + pan) * 0.5)
        sound = np.column_stack((sound * left, sound * right))
    bus[frame:frame + len(sound)] += sound * gain


def render_beat(track, frames, richness, beat_index, bridge_direction=0):
    duration = frames / RATE
    bus = np.zeros((frames, 2), dtype=np.float64)
    place(bus, pedal_pad(track, duration, richness, beat_index), 0,
          gain=0.72 + richness * 0.025, pan=(-0.08 if beat_index % 2 else 0.08))

    kick_gain = 0.68 + min(richness, 6) * 0.032
    place(bus, PRODUCTION.kick(track.character), 0, gain=kick_gain)
    place(bus, sub_pulse(track, duration * 0.72, 1.12 if beat_index % 4 == 0 else 0.92), 0,
          gain=0.84)

    if richness >= 1 and beat_index % 2 == 1:
        place(bus, PRODUCTION.round_percussion((152, 132, 172)[track.character], track.character),
              int(frames * 0.50), gain=0.34 + richness * 0.025,
              pan=(-0.22 if beat_index % 4 == 1 else 0.22))
    if richness >= 2 and beat_index % 4 in (0, 2):
        place(bus, sub_pulse(track, duration * 0.34, 0.72), int(frames * 0.50), gain=0.55)
    if richness >= 3:
        place(bus, PRODUCTION.round_percussion((205, 184, 220)[track.character], track.character),
              int(frames * 0.75), gain=0.20 + richness * 0.018,
              pan=(0.30 if beat_index % 2 else -0.30))
    if richness >= 5:
        place(bus, sub_pulse(track, duration * 0.22, 0.55), int(frames * 0.25), gain=0.38)
    if richness >= 6:
        place(bus, PRODUCTION.round_percussion((236, 210, 248)[track.character], track.character),
              int(frames * 0.375), gain=0.14, pan=(-0.36 if beat_index % 2 else 0.36))

    if bridge_direction:
        fill_positions = (0.52, 0.68, 0.82) if bridge_direction > 0 else (0.48, 0.66)
        for fill_index, position in enumerate(fill_positions):
            place(bus, PRODUCTION.round_percussion(
                (188, 165, 202)[track.character] * (1 + fill_index * 0.08), track.character
            ), int(frames * position), gain=0.24 + fill_index * 0.035,
                  pan=(-0.34 if fill_index % 2 else 0.34))

    # Short, contained stereo reflections add space without leaking across cue boundaries.
    dry = bus.copy()
    for delay_seconds, gain, channel in ((0.061, 0.045, 0), (0.083, 0.035, 1)):
        shift = int(round(delay_seconds * RATE))
        if shift < frames:
            bus[shift:, channel] += dry[:-shift, 1 - channel] * gain
    return exact_zero_edges(PRODUCTION.soft_clip(bus, 1.05), frames=384)


def render_loop(track, bpm, richness):
    frames = beat_frames(bpm)
    return np.concatenate([
        render_beat(track, frames, richness, beat_index)
        for beat_index in range(16)
    ])


def render_bridge(track, from_spec, to_spec):
    _, from_bpm, from_richness = from_spec
    _, to_bpm, to_richness = to_spec
    frames = int(round((beat_frames(from_bpm) + beat_frames(to_bpm)) / 2))
    direction = 1 if to_bpm >= from_bpm else -1
    richness = max(from_richness, to_richness)
    return render_beat(track, frames, richness, 15, bridge_direction=direction)


def append_island(sprite_parts, manifest_entry, audio):
    current_frames = sum(len(part) for part in sprite_parts)
    sprite_parts.append(np.zeros((GUARD_FRAMES, 2), dtype=np.float64))
    manifest_entry["offsetFrames"] = current_frames + GUARD_FRAMES
    manifest_entry["durationFrames"] = len(audio)
    sprite_parts.append(audio)


def write_pcm32(path, audio):
    peak = float(np.max(np.abs(audio)))
    if peak > 0:
        audio = audio / max(1.0, peak / 0.92)
    pcm = np.int32(np.clip(audio, -1, 1) * 2_147_483_647)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(4)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i4").tobytes())


def write_pcm16(path, audio, channels=2):
    pcm = np.int16(np.clip(audio, -1, 1) * 32_767)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(channels)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i2").tobytes())


def read_pcm16(path, channels=2):
    with wave.open(str(path), "rb") as source:
        frames = source.readframes(source.getnframes())
    return np.frombuffer(frames, dtype="<i2").reshape(-1, channels).astype(np.float64) / 32_768


def master_sprite(audio, manifest, output_path):
    with tempfile.TemporaryDirectory(prefix="speedytapper-interactive-") as temporary:
        temporary_path = Path(temporary)
        source_path = temporary_path / "source.wav"
        mastered_path = temporary_path / "mastered.wav"
        write_pcm32(source_path, audio)
        subprocess.run(
            [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(source_path),
                "-af",
                (
                    "highpass=f=25,lowpass=f=9000,highshelf=f=4800:g=-3.5,"
                    "acompressor=threshold=0.24:ratio=2.0:attack=16:release=170:makeup=1.10,"
                    "volume=1.8dB,alimiter=limit=0.91"
                ),
                "-ar", str(RATE), "-c:a", "pcm_s16le", str(mastered_path),
            ],
            check=True,
        )
        mastered = read_pcm16(mastered_path)

    for item in (*manifest["sections"], *manifest["transitions"]):
        start = item["offsetFrames"]
        end = start + item["durationFrames"]
        mastered[start:end] = exact_zero_edges(mastered[start:end], frames=384)
    write_pcm16(output_path, mastered)
    return mastered


def render_note_bank(track, output_path):
    slots = []
    for mode_offset in note_offsets(track):
        note = track.root + 24 + mode_offset
        sound = PRODUCTION.muted_voice(note, NOTE_SOUND_FRAMES / RATE, track.character, 1.0)
        fixed_envelope = np.ones(NOTE_SOUND_FRAMES, dtype=np.float64)
        fixed_envelope[:NOTE_ATTACK_FRAMES] *= (
            np.sin(np.linspace(0, np.pi / 2, NOTE_ATTACK_FRAMES)) ** 2
        )
        fixed_envelope[-NOTE_RELEASE_FRAMES:] *= (
            np.cos(np.linspace(0, np.pi / 2, NOTE_RELEASE_FRAMES)) ** 2
        )
        sound *= fixed_envelope
        sound = exact_zero_edges(sound, frames=384)
        rms = float(np.sqrt(np.mean(sound * sound)))
        if rms <= 0:
            raise RuntimeError(f"Silent generated note for {track.filename}")
        sound *= NOTE_RMS_TARGET / rms
        peak = float(np.max(np.abs(sound)))
        if peak > NOTE_PEAK_LIMIT:
            raise RuntimeError(
                f"Generated note peak exceeds headroom for {track.filename}: {peak:.4f}"
            )
        slot = np.zeros(NOTE_SLOT_FRAMES, dtype=np.float64)
        slot[:len(sound)] = sound
        slots.append(slot)
    bank = np.concatenate(slots)
    write_pcm16(output_path, bank, channels=1)


def encode_aac(track, master_path, runtime_path):
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(master_path), "-map_metadata", "-1",
            "-metadata", f"title={track.title} Interactive Backing",
            "-metadata", "artist=SpeedyTapper",
            "-metadata", "comment=Original backing-only Interactive Music sprite",
            "-ar", str(RATE), "-c:a", "aac", "-profile:a", "aac_low", "-b:a", "192k",
            str(runtime_path),
        ],
        check=True,
    )


def decoded_aac(path):
    decoded_bytes = subprocess.check_output(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-i", str(path),
            "-ar", str(RATE), "-ac", "2", "-c:a", "pcm_s16le", "-f", "s16le", "-",
        ]
    )
    return np.frombuffer(decoded_bytes, dtype="<i2").reshape(-1, 2).astype(np.float64) / 32_768


def verify_assets(track, manifest, master_path, runtime_path, note_path):
    probe = json.loads(subprocess.check_output(
        [
            "ffprobe", "-v", "error", "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,profile,sample_rate,channels,bit_rate",
            "-of", "json", str(runtime_path),
        ],
        text=True,
    ))["streams"][0]
    if (
        probe.get("codec_name") != "aac"
        or probe.get("profile") != "LC"
        or int(probe.get("sample_rate", 0)) != RATE
        or int(probe.get("channels", 0)) != 2
    ):
        raise RuntimeError(f"Unexpected AAC format for {runtime_path}: {probe}")

    master = read_pcm16(master_path)
    decoded = decoded_aac(runtime_path)
    if len(decoded) < len(master) or len(decoded) - len(master) > 1_023:
        raise RuntimeError(f"Decoded duration drift for {runtime_path}: {len(decoded)} vs {len(master)}")
    decoded = decoded[:len(master)]
    maximum_step = float(np.max(np.abs(np.diff(decoded, axis=0))))
    if maximum_step > 0.20:
        raise RuntimeError(f"Impulse-like AAC discontinuity for {runtime_path}: {maximum_step:.5f}")

    seam_jumps = []
    edge_peaks = []
    for section in manifest["sections"]:
        start = section["offsetFrames"]
        end = start + section["durationFrames"]
        seam_jumps.append(float(np.max(np.abs(decoded[end - 1] - decoded[start]))))
        edge = np.concatenate((decoded[start:start + 256], decoded[end - 256:end]))
        edge_peaks.append(float(np.max(np.abs(edge))))
    if max(seam_jumps) > 0.035 or max(edge_peaks) > 0.08:
        raise RuntimeError(
            f"Unsafe decoded loop edge for {runtime_path}: jumps={seam_jumps}, peaks={edge_peaks}"
        )

    section_by_id = {section["id"]: section for section in manifest["sections"]}
    transition_join_jumps = []
    transition_join_labels = []
    crossfaded_source_jumps = []
    hard_target_jumps = []
    for transition in manifest["transitions"]:
        source_section = section_by_id[transition["from"]]
        target_section = section_by_id[transition["to"]]
        bridge_start = transition["offsetFrames"]
        bridge_end = bridge_start + transition["durationFrames"]
        for beat_index in range(1, 17):
            source_boundary = (
                source_section["offsetFrames"] +
                beat_index * source_section["beatFrames"]
            )
            source_jump = float(np.max(np.abs(
                decoded[source_boundary - 1] - decoded[bridge_start]
            )))
            transition_join_jumps.append(source_jump)
            crossfaded_source_jumps.append(source_jump)
            transition_join_labels.append(
                f"{transition['from']} beat {beat_index} -> {transition['to']} bridge"
            )
        target_jump = float(np.max(np.abs(
            decoded[bridge_end - 1] - decoded[target_section["offsetFrames"]]
        )))
        transition_join_jumps.append(target_jump)
        hard_target_jumps.append(target_jump)
        transition_join_labels.append(
            f"{transition['from']} bridge -> {transition['to']}"
        )
    if max(hard_target_jumps) > 0.035:
        target_labels = [label for label in transition_join_labels if "bridge ->" in label]
        worst_join_index = int(np.argmax(hard_target_jumps))
        raise RuntimeError(
            f"Unsafe decoded bridge-to-loop join for {runtime_path}: "
            f"{hard_target_jumps[worst_join_index]:.5f} at "
            f"{target_labels[worst_join_index]}"
        )

    with wave.open(str(note_path), "rb") as note_file:
        if (
            note_file.getframerate() != RATE
            or note_file.getnchannels() != 1
            or note_file.getsampwidth() != 2
            or note_file.getnframes() != NOTE_SLOT_FRAMES * len(note_offsets(track))
        ):
            raise RuntimeError(f"Unexpected note-bank format for {note_path}")
        note_pcm = np.frombuffer(note_file.readframes(note_file.getnframes()), dtype="<i2")
    note_rms_values = []
    for index in range(len(note_offsets(track))):
        slot = note_pcm[index * NOTE_SLOT_FRAMES:(index + 1) * NOTE_SLOT_FRAMES]
        rendered = slot[:NOTE_SOUND_FRAMES].astype(np.float64) / 32_768
        tail = slot[NOTE_SOUND_FRAMES:]
        if slot[0] != 0 or rendered[-1] != 0 or slot[-1] != 0 or np.any(tail):
            raise RuntimeError(f"Non-zero note boundary in {note_path}, slot {index}")
        note_rms_values.append(float(np.sqrt(np.mean(rendered * rendered))))
    if max(abs(value - NOTE_RMS_TARGET) for value in note_rms_values) > 0.0001:
        raise RuntimeError(
            f"Uneven note energy in {note_path}: {note_rms_values}"
        )

    core_audio_frames = None
    afinfo_path = shutil.which("afinfo")
    if afinfo_path:
        info = subprocess.check_output([afinfo_path, str(runtime_path)], text=True)
        for line in info.splitlines():
            if "valid frames" in line and "audio" in line:
                core_audio_frames = int(line.split("audio", 1)[1].split("valid frames", 1)[0].strip())
                break
        if core_audio_frames is None or core_audio_frames < len(master):
            raise RuntimeError(f"Unexpected CoreAudio frame report for {runtime_path}")

    print(
        f"QA {track.title}: {len(master) / RATE:.2f}s AAC-{probe['profile']} "
        f"{int(probe.get('bit_rate', 0)) // 1000}kbps, max-step {maximum_step:.4f}, "
        f"max-loop-edge {max(edge_peaks):.4f}, "
        f"max-crossfaded-source-edge {max(crossfaded_source_jumps):.4f}, "
        f"max-bridge-target-join {max(hard_target_jumps):.4f}, "
        f"tail-padding {len(decoded_aac(runtime_path)) - len(master)}, "
        f"CoreAudio-valid {core_audio_frames or 'n/a'}"
    )
    return core_audio_frames


def build_manifest():
    return {
        "sampleRate": RATE,
        "guardFrames": GUARD_FRAMES,
        "noteSlotFrames": NOTE_SLOT_FRAMES,
        "noteSoundFrames": NOTE_SOUND_FRAMES,
        "noteRmsTarget": NOTE_RMS_TARGET,
        "sections": [],
        "transitions": [],
        "tracks": {},
    }


def render_track(track, shared_manifest):
    sprite_parts = []
    track_manifest = {"sections": [], "transitions": []}
    for identifier, bpm, richness in SECTION_SPECS:
        entry = {
            "id": identifier,
            "bpm": bpm,
            "beatFrames": beat_frames(bpm),
            "richness": richness,
        }
        append_island(sprite_parts, entry, render_loop(track, bpm, richness))
        track_manifest["sections"].append(entry)

    pairs = []
    for index in range(len(SECTION_SPECS) - 1):
        pairs.append((SECTION_SPECS[index], SECTION_SPECS[index + 1]))
    for index in range(len(SECTION_SPECS) - 1, 0, -1):
        pairs.append((SECTION_SPECS[index], SECTION_SPECS[index - 1]))
    for from_spec, to_spec in pairs:
        entry = {"from": from_spec[0], "to": to_spec[0]}
        append_island(sprite_parts, entry, render_bridge(track, from_spec, to_spec))
        track_manifest["transitions"].append(entry)

    sprite_parts.append(np.zeros((GUARD_FRAMES, 2), dtype=np.float64))
    sprite = np.concatenate(sprite_parts)
    master_path = MASTER_OUT / f"{track.filename}.wav"
    runtime_path = RUNTIME_OUT / f"interactive-{track.filename}.m4a"
    note_path = RUNTIME_OUT / note_bank_filename(track)
    mastered = master_sprite(sprite, track_manifest, master_path)
    encode_aac(track, master_path, runtime_path)
    render_note_bank(track, note_path)
    core_audio_frames = verify_assets(
        track, track_manifest, master_path, runtime_path, note_path
    )

    if not shared_manifest["sections"]:
        shared_manifest["sections"] = track_manifest["sections"]
        shared_manifest["transitions"] = track_manifest["transitions"]
        shared_manifest["masterFrames"] = len(mastered)
    elif (
        shared_manifest["sections"] != track_manifest["sections"]
        or shared_manifest["transitions"] != track_manifest["transitions"]
        or shared_manifest["masterFrames"] != len(mastered)
    ):
        raise RuntimeError("Interactive track manifests must have identical cue frames")
    shared_manifest["tracks"][track.filename] = {
        "backing": runtime_path.name,
        "master": master_path.name,
        "notes": note_path.name,
        "noteScaleDegreeCount": note_scale_degree_count(track),
        "noteSlotCount": len(note_offsets(track)),
        "noteOffsets": list(note_offsets(track)),
        "motif": list(MOTIFS[track.filename]),
        "coreAudioValidFrames": core_audio_frames,
    }


def verify_retained(shared_manifest):
    for track in PRODUCTION.TRACKS:
        retained_track = shared_manifest["tracks"][track.filename]
        expected_note_metadata = {
            "notes": note_bank_filename(track),
            "noteScaleDegreeCount": note_scale_degree_count(track),
            "noteSlotCount": len(note_offsets(track)),
            "noteOffsets": list(note_offsets(track)),
            "motif": list(MOTIFS[track.filename]),
        }
        for key, expected_value in expected_note_metadata.items():
            if retained_track.get(key) != expected_value:
                raise RuntimeError(
                    f"Note metadata drift for {track.filename} {key}: "
                    f"{retained_track.get(key)} vs {expected_value}"
                )
        expected_scale_degree_count = note_scale_degree_count(track)
        retained_scale_degree_count = retained_track.get("noteScaleDegreeCount")
        if retained_scale_degree_count != expected_scale_degree_count:
            raise RuntimeError(
                f"Note scale drift for {track.filename}: "
                f"{retained_scale_degree_count} vs {expected_scale_degree_count}"
            )
        track_manifest = {
            "sections": shared_manifest["sections"],
            "transitions": shared_manifest["transitions"],
        }
        core_audio_frames = verify_assets(
            track,
            track_manifest,
            MASTER_OUT / f"{track.filename}.wav",
            RUNTIME_OUT / f"interactive-{track.filename}.m4a",
            RUNTIME_OUT / note_bank_filename(track),
        )
        expected = retained_track.get("coreAudioValidFrames")
        if expected is not None and core_audio_frames != expected:
            raise RuntimeError(
                f"CoreAudio frame drift for {track.filename}: {core_audio_frames} vs {expected}"
            )


def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--verify-only",
        action="store_true",
        help="Verify retained Interactive Music assets without regenerating them.",
    )
    mode.add_argument(
        "--notes-only",
        action="store_true",
        help="Regenerate only uniform tap-note banks and their manifest metadata.",
    )
    arguments = parser.parse_args()
    MASTER_OUT.mkdir(parents=True, exist_ok=True)
    if arguments.verify_only:
        verify_retained(json.loads(MANIFEST_PATH.read_text()))
        return

    if arguments.notes_only:
        manifest = json.loads(MANIFEST_PATH.read_text())
        manifest["noteSlotFrames"] = NOTE_SLOT_FRAMES
        manifest["noteSoundFrames"] = NOTE_SOUND_FRAMES
        manifest["noteRmsTarget"] = NOTE_RMS_TARGET
        for track in PRODUCTION.TRACKS:
            note_path = RUNTIME_OUT / note_bank_filename(track)
            render_note_bank(track, note_path)
            track_manifest = manifest["tracks"][track.filename]
            track_manifest.update({
                "notes": note_path.name,
                "noteScaleDegreeCount": note_scale_degree_count(track),
                "noteSlotCount": len(note_offsets(track)),
                "noteOffsets": list(note_offsets(track)),
                "motif": list(MOTIFS[track.filename]),
            })
            verify_assets(
                track,
                {
                    "sections": manifest["sections"],
                    "transitions": manifest["transitions"],
                },
                MASTER_OUT / f"{track.filename}.wav",
                RUNTIME_OUT / f"interactive-{track.filename}.m4a",
                note_path,
            )
        MANIFEST_PATH.write_text(json.dumps(manifest, indent=2) + "\n")
        return

    manifest = build_manifest()
    for track in PRODUCTION.TRACKS:
        render_track(track, manifest)
    MANIFEST_PATH.write_text(json.dumps(manifest, indent=2) + "\n")


if __name__ == "__main__":
    main()
