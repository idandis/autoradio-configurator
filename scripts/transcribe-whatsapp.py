import json
import os
import subprocess
import sys

os.environ['HF_HUB_OFFLINE'] = '1'
os.environ['TOKENIZERS_PARALLELISM'] = 'false'

import torch
import whisper

torch.set_num_threads(4)
probe = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
    '-of', 'json', sys.argv[1]], capture_output=True, text=True, check=True, timeout=10)
duration = float(json.loads(probe.stdout)['format']['duration'])
if not 0.3 <= duration <= 120:
    raise ValueError('Audio must be between 0.3 and 120 seconds')
audio = whisper.load_audio(sys.argv[1])
if not 0.3 <= len(audio) / whisper.audio.SAMPLE_RATE <= 120:
    raise ValueError('Audio must be between 0.3 and 120 seconds')
model = whisper.load_model(sys.argv[2], device='cpu')
result = model.transcribe(audio, fp16=False, verbose=None, temperature=0,
                          condition_on_previous_text=False)
segments = result.get('segments', [])
valid = bool(segments) and all(s.get('avg_logprob', -10) >= -0.8
    and s.get('no_speech_prob', 1) < 0.6 and s.get('compression_ratio', 10) <= 2.4
    for s in segments)
print(json.dumps({'text': result['text'].strip() if valid else '',
                  'language': result.get('language')}))
