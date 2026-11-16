"""Generate tiny files used by real media upload forms (requires ffmpeg)."""
from pathlib import Path
import base64, subprocess
state=Path(__file__).resolve().parent/'.local/state'
state.mkdir(parents=True,exist_ok=True)
(state/'upload.png').write_bytes(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGNQmBlKEmIY1TCqYfhqAABWQw4QqWAGPwAAAABJRU5ErkJggg=='))
(state/'upload.pdf').write_bytes(b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n')
for extension,args in [('mp4',['-f','lavfi','-i','color=c=green:s=32x32:d=0.2','-an','-c:v','libx264','-pix_fmt','yuv420p']),('wav',['-f','lavfi','-i','anullsrc=r=8000:cl=mono','-t','0.2'])]:
 subprocess.run(['ffmpeg','-y','-hide_banner','-loglevel','error',*args,str(state/('upload.'+extension))],check=True)
