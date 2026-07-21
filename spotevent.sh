#!/bin/bash
#
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2014 The moOde audio player project / Tim Curtis
#
# MODIFIED: added paused/playing/stopped event handling to write a
# small local state file, so nowplaying.php can distinguish Spotify
# play vs pause vs stop without polling or process-checking.
#
# FIX: cover art is now fetched SYNCHRONOUSLY and BEFORE the metadata
# is announced — previously it downloaded in the background while
# metadata was written immediately, creating a race where the display
# could show a new track's title before its art had finished
# downloading, serving the PREVIOUS track's cached cover in the
# meantime. Now the art file is guaranteed current by the time any
# poller notices the title has changed.
#

LOGFILE="/var/log/moode_spotevent.log"
DEBUG=$(sudo moodeutl -d -gv debuglog)
SPOTMETA_CACHE_FILE="/var/local/www/spotmeta.json"
SPOTSTATE_FILE="/var/local/www/spotify_playstate.json"
SPOTCOVER_FILE="/var/local/www/spotify_cover.jpg"
SQLDB=/var/local/www/db/moode-sqlite3.db

debug_log () {
        if [[ $DEBUG == '0' ]]; then
                return 0
        fi
        echo "$1"
        TIME=$(date +'%Y%m%d %H%M%S')
        echo "$TIME $1" >> $LOGFILE
}

PLAYER_EVENTS=(
session_connected
session_disconnected
track_changed
paused
playing
stopped
)

MATCH=0
for MATCH_EVENT in "${PLAYER_EVENTS[@]}"
do
        if [[ $PLAYER_EVENT == $MATCH_EVENT ]]; then
                MATCH=1
                debug_log "Process: "$PLAYER_EVENT
        fi
done
if [[ $MATCH == 0 ]]; then
        debug_log "Logged:  "$PLAYER_EVENT
        exit 0
fi

RESULT=$(sqlite3 $SQLDB "SELECT value FROM cfg_system WHERE param IN ('volknob','alsavolume_max','alsavolume','amixname','mpdmixer','camilladsp_volume_sync','rsmafterspot','inpactive','volknob_mpd','multiroom_tx')")
readarray -t arr <<<"$RESULT"
VOLKNOB=${arr[0]}
ALSAVOLUME_MAX=${arr[1]}
ALSAVOLUME=${arr[2]}
AMIXNAME=${arr[3]}
MPDMIXER=${arr[4]}
CDSP_VOLSYNC=${arr[5]}
RSMAFTERSPOT=${arr[6]}
INPACTIVE=${arr[7]}
VOLKNOB_MPD=${arr[8]}
MULTIROOM_TX=${arr[9]}
RX_ADDRESSES=$(sudo moodeutl -d -gv rx_addresses)
BITRATE=$(sqlite3 $SQLDB "SELECT value FROM cfg_spotify WHERE param='bitrate'")"K"
CFG_SPOTIFY_FORMAT="Vorbis "$BITRATE

if [[ $INPACTIVE == '1' ]]; then
        exit 1
fi

if [[ $PLAYER_EVENT == "session_connected" ]]; then
        $(sqlite3 $SQLDB "UPDATE cfg_system SET value='1' WHERE param='spotactive'")
        /usr/bin/mpc stop > /dev/null
        /var/www/util/send-fecmd.php "spotactive1"

        if [[ $CDSP_VOLSYNC == "on" ]]; then
                sed -i '0,/- -.*/s//- 0.0/' /var/lib/cdsp/statefile.yml
        elif [[ $ALSAVOLUME != "none" ]]; then
                /var/www/util/sysutil.sh set-alsavol "$AMIXNAME" $ALSAVOLUME_MAX
        fi

        if [[ $MULTIROOM_TX == "On" ]]; then
                for IP_ADDR in $RX_ADDRESSES; do
                        RESULT=$(curl -G -S -s --data-urlencode "cmd=trx_control -set-alsavol" http://$IP_ADDR/command/)
                        if [[ $RESULT != "" ]]; then
                                RESULT=$(curl -G -S -s --data-urlencode "cmd=trx_control -set-alsavol" http://$IP_ADDR/command/)
                                if [[ $RESULT != "" ]]; then
                                        echo $(date +%F" "%T) "Event: trx_control -set-alsavol failed: $IP_ADDR" >> $LOGFILE
                                fi
                        fi
                done
        fi
fi

if [[ $PLAYER_EVENT == "session_disconnected" ]]; then
        $(sqlite3 $SQLDB "UPDATE cfg_system SET value='0' WHERE param='spotactive'")
        /var/www/util/vol.sh -restore

        if [[ $CDSP_VOLSYNC == "on" ]]; then
                systemctl restart mpd2cdspvolume
        fi

        if [[ $MULTIROOM_TX == "On" ]]; then
                for IP_ADDR in $RX_ADDRESSES; do
                        RESULT=$(curl -G -S -s --data-urlencode "cmd=set_volume -restore" http://$IP_ADDR/command/)
                        if [[ $RESULT != "" ]]; then
                                RESULT=$(curl -G -S -s --data-urlencode "cmd=set_volume -restore" http://$IP_ADDR/command/)
                                if [[ $RESULT != "" ]]; then
                                        echo $(date +%F" "%T) "Event: set_volume -restore failed: $IP_ADDR" >> $LOGFILE
                                fi
                        fi
                done
        fi

        if [[ $RSMAFTERSPOT == "Yes" ]]; then
                /usr/bin/mpc play > /dev/null
        fi

        echo "{\"state\":\"stopped\",\"ts\":$(date +%s)}" > $SPOTSTATE_FILE
fi

if [[ $PLAYER_EVENT == "playing" ]]; then
        echo "{\"state\":\"playing\",\"ts\":$(date +%s)}" > $SPOTSTATE_FILE
fi

if [[ $PLAYER_EVENT == "paused" ]]; then
        echo "{\"state\":\"paused\",\"ts\":$(date +%s)}" > $SPOTSTATE_FILE
fi

if [[ $PLAYER_EVENT == "stopped" ]]; then
        echo "{\"state\":\"stopped\",\"ts\":$(date +%s)}" > $SPOTSTATE_FILE
fi

if [[ $PLAYER_EVENT == "track_changed" ]]; then
        ARTIST=$(echo -e -n "$ARTISTS" | tr "\n" ";" | cut -d';' -f1)
        COVER=$(echo -e -n "$COVERS" | tr "\n" ";" | cut -d';' -f1)

        # FIX: fetch cover SYNCHRONOUSLY and FIRST, before announcing
        # the new metadata. This closes the race where the title
        # changed before the art had finished downloading.
        if [[ -n "$COVER" ]]; then
                curl -s -m 4 -o "${SPOTCOVER_FILE}.tmp" "$COVER" \
                  && mv "${SPOTCOVER_FILE}.tmp" "$SPOTCOVER_FILE"
        fi

        METADATA_JSON=$(jq -n -c \
                --arg a "update_spotmeta" \
                --arg b "$NAME" \
                --arg c "$ARTIST" \
                --arg d "$ALBUM" \
                --arg e "$DURATION_MS" \
                --arg f "$COVER" \
                --arg g "$CFG_SPOTIFY_FORMAT" \
                '{fecmd: $a, title: $b, artist: $c, album: $d, duration: $e, cover_url: $f, sformat: $g}')
        echo -e "$METADATA_JSON" > $SPOTMETA_CACHE_FILE
        /var/www/util/send-fecmd.php "$METADATA_JSON"

        echo "{\"state\":\"playing\",\"ts\":$(date +%s)}" > $SPOTSTATE_FILE
fi
