#!/usr/bin/env python3
"""Metadata-only diagnostic. No redirects, manifests, segments or keys downloaded.
Read an operator-approved URL from --url-file to keep query credentials out of args.
"""
import argparse
import json
import ssl
import urllib.error
import urllib.parse
import urllib.request

parser=argparse.ArgumentParser()
parser.add_argument('--url-file')
args=parser.parse_args()
url=open(args.url_file).read().strip() if args.url_file else 'https://cdn.goldendragon2000.com/'
parts=urllib.parse.urlsplit(url)
if parts.scheme!='https' or parts.hostname!='cdn.goldendragon2000.com' or parts.port not in (None,443) or parts.username or parts.password or parts.fragment:
    raise SystemExit('Only credential-free HTTPS URLs on the existing CDN host are accepted; query signatures may remain in the protected file.')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
opener=urllib.request.build_opener(NoRedirect(),urllib.request.HTTPSHandler(context=ssl.create_default_context()))
result={'host':parts.hostname,'scope':'HTTP metadata only; no media fetched; redirects not followed','requests':[]}
for method,extra in [('HEAD',{}),('OPTIONS',{'Access-Control-Request-Method':'GET','Access-Control-Request-Headers':'Range'})]:
    row={'method':method}
    try:
        request=urllib.request.Request(url,method=method,headers={'Origin':'https://ott.zentunnel.net',**extra})
        try: response=opener.open(request,timeout=15)
        except urllib.error.HTTPError as error:response=error
        with response:
            row['status']=response.code
            safe=['Server','Content-Type','Access-Control-Allow-Origin','Access-Control-Allow-Credentials','Access-Control-Allow-Methods','Access-Control-Allow-Headers','Vary','Accept-Ranges']
            row['headers']={k:response.headers[k] for k in safe if k in response.headers}
            row['set_cookie_present']=bool(response.headers.get_all('Set-Cookie'))
            row['redirect_present']=bool(response.headers.get('Location'))
            row['auth_challenge_present']=bool(response.headers.get('WWW-Authenticate'))
            allowed=response.headers.get('Access-Control-Allow-Origin')
            row['anonymous_cors_header_compatible']=allowed in ('*','https://ott.zentunnel.net')
            row['credentialed_cors_header_compatible']=allowed=='https://ott.zentunnel.net' and response.headers.get('Access-Control-Allow-Credentials','').lower()=='true'
    except Exception as error:
        # Do not echo URLs, query credentials, certificate contents or response bodies.
        row['error_type']=type(error).__name__
    result['requests'].append(row)
result['access_control_method']='UNKNOWN: headers cannot prove a signing algorithm, cookie protocol, or authorization coverage.'
print(json.dumps(result,indent=2))
