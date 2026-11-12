#!/usr/bin/env python3
"""Read-only HTTP checks through the v3 PHP-FPM socket; run as www-data."""
import socket,struct,json,re
from urllib.parse import urlsplit

def record(kind,data=b''):
 return struct.pack('!BBHHBB',1,kind,1,len(data),0,0)+data

def length(n):return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
def request(uri):
 q=urlsplit(uri)
 env={'GATEWAY_INTERFACE':'CGI/1.1','SERVER_PROTOCOL':'HTTP/1.1','REQUEST_METHOD':'GET','SCRIPT_FILENAME':'/mnt/data/labdoo/web/index.php','SCRIPT_NAME':'/index.php','PHP_SELF':'/index.php','DOCUMENT_ROOT':'/mnt/data/labdoo/web','REQUEST_URI':uri,'QUERY_STRING':q.query,'SERVER_NAME':'v3.labdoo.org','HTTP_HOST':'v3.labdoo.org','SERVER_PORT':'443','HTTPS':'on','REQUEST_SCHEME':'https','REMOTE_ADDR':'127.0.0.1','REMOTE_PORT':'12345','SERVER_ADDR':'127.0.0.1','HTTP_ACCEPT':'text/html,application/json','HTTP_USER_AGENT':'LabdooDeploymentVerification/1.0','CONTENT_LENGTH':'0'}
 params=b''.join(length(len(k.encode()))+length(len(v.encode()))+k.encode()+v.encode() for k,v in env.items())
 s=socket.socket(socket.AF_UNIX);s.settimeout(60);s.connect('/run/php/php8.3-fpm.sock');s.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,params)+record(4)+record(5))
 output=b'';errors=b''
 def read(n):
  b=b''
  while len(b)<n:
   p=s.recv(n-len(b))
   if not p:raise RuntimeError('Premature FPM close')
   b+=p
  return b
 while True:
  version,kind,rid,size,padding,_=struct.unpack('!BBHHBB',read(8));data=read(size) if size else b''
  if padding:read(padding)
  if kind==6:output+=data
  elif kind==7:errors+=data
  elif kind==3:break
 s.close();raw,_,body=output.partition(b'\r\n\r\n');headers={k.lower():v.strip() for line in raw.decode().splitlines() if ':' in line for k,v in [line.split(':',1)]};status=int(headers.get('status','200').split()[0]);text=body.decode(errors='replace')
 result={'uri':uri,'status':status,'dpc':headers.get('x-drupal-dynamic-cache'),'theme':headers.get('x-labdoo-theme'),'fpm_errors':len(errors)}
 assert status==200,result
 assert not errors, "PHP-FPM emitted stderr"
 if 'education-quote' in uri:
  data=json.loads(text);assert data['language']=='en' and data['quote']['id']!='mandela';result['fresh_quote']=True
 else:
  cls=re.search(r'<body[^>]*class="([^"]*)',text).group(1)
  result['next_body']='labdoo-next' in cls
  if 'theme=labdoo_next' in uri:
   assert result['next_body'] and 'labdoo-wordmark.webp' in text and 'src=""' not in text,result
  else:assert not result['next_body'],result
 print(json.dumps(result),flush=True)
for uri in ['/en?palette=ocean&theme=labdoo_next','/en?palette=ocean&theme=labdoo_next','/es/content/dootronics-dashboard?palette=ocean&theme=labdoo_next','/en','/es/content/dootronics-dashboard','/en/labdoo/education-quote/en?exclude=mandela']:request(uri)
