"""Import the pinned public-domain Free Exercise DB catalog and first image per exercise."""
import concurrent.futures
import json
import pathlib
import urllib.request
import urllib.parse

root = pathlib.Path(__file__).resolve().parents[1]
revision = 'f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5'
base = f'https://raw.githubusercontent.com/yuhonas/free-exercise-db/{revision}/'
source = json.load(urllib.request.urlopen(base + 'dist/exercises.json'))
output = root / 'public/exercise-library'
output.mkdir(parents=True, exist_ok=True)
license_text = urllib.request.urlopen(base + 'LICENSE.md').read()
(output / 'LICENSE.md').write_bytes(license_text)

def convert(item):
    key = item['id']
    assert pathlib.PurePosixPath(key).name == key and key not in ('.', '..')
    image_path = item['images'][0] if item['images'] else None
    target = output / (key + '.jpg')
    if image_path and not target.exists():
        target.write_bytes(urllib.request.urlopen(base + 'exercises/' + urllib.parse.quote(image_path), timeout=45).read())
    muscle = (item['primaryMuscles'] or ['other'])[0]
    focus = {'abdominals':'Core', 'abductors':'Legs', 'adductors':'Legs', 'biceps':'Arms', 'calves':'Legs', 'chest':'Chest', 'forearms':'Arms', 'glutes':'Legs', 'hamstrings':'Legs', 'lats':'Back', 'lower back':'Back', 'middle back':'Back', 'neck':'Shoulders', 'quadriceps':'Legs', 'shoulders':'Shoulders', 'traps':'Back', 'triceps':'Arms'}.get(muscle, 'Full Body')
    category = item['category']
    name = item['name']
    equipment = item['equipment'] or 'body only'
    if category == 'cardio':
        focus = 'Cardio'
        icon = 'bike' if 'cycl' in name.lower() or 'bike' in name.lower() else 'footprints' if 'run' in name.lower() or 'walk' in name.lower() else 'activity'
    elif category == 'stretching':
        icon = 'stretch'
    elif equipment in ('barbell', 'ez curl bar'):
        icon = 'weight'
    elif equipment in ('dumbbell', 'kettlebells'):
        icon = 'dumbbell'
    else:
        icon = {'Arms':'biceps', 'Legs':'footprints', 'Core':'target', 'Full Body':'bodyweight'}.get(focus, 'dumbbell')
    return {'key':'builtin:' + key, 'name':name, 'focus_area':focus, 'equipment':equipment, 'category':category, 'muscles':item['primaryMuscles'], 'icon':icon, 'thumbnail_url':('/exercise-library/' + urllib.parse.quote(key) + '.jpg') if image_path else None, 'image':(key + '.jpg') if image_path else None}

with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    catalog = sorted(pool.map(convert, source), key=lambda row: row['name'].lower())
(root / 'resources/data/exercise-library.json').write_text(json.dumps(catalog, ensure_ascii=False, separators=(',', ':')) + '\n')
(output / 'SOURCE.json').write_text(json.dumps({'source':'https://github.com/yuhonas/free-exercise-db', 'revision':revision, 'license':'Unlicense', 'exercises':len(catalog)}, indent=2) + '\n')
print(json.dumps({'exercises':len(catalog), 'revision':revision, 'without_photo':sum(row['image'] is None for row in catalog), 'max_name_length':max(len(row['name']) for row in catalog), 'image_bytes':sum(path.stat().st_size for path in output.glob('*.jpg'))}))
