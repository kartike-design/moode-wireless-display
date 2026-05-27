#!/usr/bin/env python3
"""
Apply the spot_state.txt patch to moOde's spotevent.sh.

This patch makes librespot's pause/play/stop events write a one-line
state file that nowplaying.php reads to determine Spotify playback state.
Without this, librespot running as a persistent service means the
display never knows when Spotify is paused.

Run with: sudo python3 patch_spotevent.py
"""

import sys

TARGET = '/var/local/www/commandw/spotevent.sh'

OLD = 'if [[ $MATCH == 0 ]]; then\n\tdebug_log "Logged:  "$PLAYER_EVENT\n\texit 0\nfi'
NEW = ('if [[ $MATCH == 0 ]]; then\n'
       '\tdebug_log "Logged:  "$PLAYER_EVENT\n'
       '\tif [[ $PLAYER_EVENT == "playing" || $PLAYER_EVENT == "paused" || $PLAYER_EVENT == "stopped" ]]; then\n'
       '\t\techo "$PLAYER_EVENT" > /var/local/www/spot_state.txt\n'
       '\tfi\n'
       '\texit 0\n'
       'fi')

with open(TARGET, 'r') as f:
    content = f.read()

if OLD not in content:
    print("ERROR: Could not find the target block — already patched, or spotevent.sh has changed.")
    sys.exit(1)

content = content.replace(OLD, NEW)
with open(TARGET, 'w') as f:
    f.write(content)

print("Patched successfully.")
print("Verify with: grep -A10 'MATCH == 0' " + TARGET)
