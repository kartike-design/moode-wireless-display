# quotes.csv format

The literary clock reads a pipe-delimited CSV file at `var/www/quotes.csv`,
served from `/var/www/quotes.csv` on the Pi.

A quotes file is included in this repo, sourced from an existing public
literary-clock project. If you'd like to edit, extend, or replace it with
your own, here's the format it follows.

## Format

```
time|time_string|quote|book|author
```

| Column | Description |
|---|---|
| `time` | 24-hour `HH:MM` this quote should appear at (e.g. `14:30`) |
| `time_string` | The exact phrase within the quote that states the time — this gets highlighted in red on the display |
| `quote` | The full quote text |
| `book` | Source book/work title |
| `author` | Author name (can be left blank) |

### Example row

```
09:15|quarter past nine|It was a quarter past nine when the letter arrived.|Example Novel|Jane Author
```

The parser splits only on the first four pipe characters, so the `quote`
field itself can safely contain additional `|` characters if needed.

## Coverage

The display searches for an exact match on the current `HH:MM` first, then
falls back to searching +/-10 minutes if there's no exact entry, then picks
a random quote as a last resort — so partial coverage of the day's 1,440
minutes still works well; you don't need every single minute represented.

## Extending it

To add or edit entries, just add rows in the same pipe-delimited format
above and re-upload the file to `/var/www/quotes.csv` on your Pi (see the
main README for the upload commands).
