"""Build lossless responsive WebP derivatives of the existing theme artwork."""
import pathlib, subprocess
theme=pathlib.Path(__file__).resolve().parent.parent
root=theme.parents[3]
out=theme/'images'
sources={'labdoo-wordmark':theme/'images/labdoo-wordmark.webp'}
for name in ['hub','dootrip','laptop','edoovillage','footer-acts-transparent','labdoo-stories-header']:
 sources[name]=root/'web/themes/custom/labdoo/img'/f'{name}.png'
for name,source in sources.items():
 widths=[120,240,360] if name=='labdoo-wordmark' else [30,60,90] if name in ['hub','dootrip','laptop','edoovillage'] else [336,672,1342] if name=='footer-acts-transparent' else [400,550,1100]
 for width in widths:
  dest=out/f'{name}-{width}.webp'
  subprocess.run(['cwebp','-quiet','-lossless','-m','6','-resize',str(width),'0',str(source),'-o',str(dest)],check=True)
  print(dest.name,dest.stat().st_size)
