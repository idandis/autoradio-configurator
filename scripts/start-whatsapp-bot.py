import pathlib
import subprocess
import sys

root = pathlib.Path(sys.argv[1])
with (root / 'storage/logs/whatsapp-bot.log').open('ab') as log:
    subprocess.Popen(
        [sys.argv[2], str(root / 'artisan'), 'whatsapp:test-bot', '--minutes=120'],
        cwd=root,
        stdin=subprocess.DEVNULL,
        stdout=log,
        stderr=subprocess.STDOUT,
        close_fds=True,
        start_new_session=True,
    )
