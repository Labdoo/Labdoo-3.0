"""Build lossless responsive WebP derivatives of the existing theme artwork."""
import pathlib, subprocess
theme=pathlib.Path(__file__).resolve().parent.parent
root=theme.parents[3]
out=theme/'images'
sources={'labdoo-wordmark':theme/'images/labdoo-wordmark.webp'}
for name in ['hub','dootrip','laptop','edoovillage','footer-acts-transparent','labdoo-stories-header']:
 sources[name]=root/'web/themes/custom/labdoo/img'/f'{name}.png'
action_icons = [
 'house', 'photo-album-icon', 'pencil-icon', 'revisions-icon', 'clone-icon',
 'arrow-left', 'arrow-right', 'tag', 'signal', 'signal-off', 'truck', 'truck-off',
 'home', 'all-teams', 'wall', 'feed', 'team-members', 'check', 'conversation',
]
for name in action_icons:
 sources[name]=root/'web/themes/custom/labdoo/img'/f'{name}.png'
for source in sorted((root/'web/themes/custom/labdoo/img').glob('semaphore-*-en.png')):
 sources[source.stem]=source
for name,source in sources.items():
 # Preserve the feed icon's native resolution instead of enlarging it.
 widths=[16] if name=='feed' else [120,240,360] if name=='labdoo-wordmark' or name.startswith('semaphore-') else [30,60,90] if name in ['hub','dootrip','laptop','edoovillage',*action_icons] else [336,672,1342] if name=='footer-acts-transparent' else [400,550,1100]
 for width in widths:
  dest=out/f'{name}-{width}.webp'
  subprocess.run(['cwebp','-quiet','-lossless','-m','6','-resize',str(width),'0',str(source),'-o',str(dest)],check=True)
  print(dest.name,dest.stat().st_size)
