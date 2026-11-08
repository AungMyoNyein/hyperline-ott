<?php
return ['paths'=>['api/*'],'allowed_methods'=>['GET','POST','PUT','PATCH','DELETE','OPTIONS'],'allowed_origins'=>array_filter(explode(',',env('CORS_ALLOWED_ORIGINS','https://ott.zentunnel.net,https://cms.zentunnel.net'))),'allowed_origins_patterns'=>[],'allowed_headers'=>['Content-Type','Authorization','Accept'],'exposed_headers'=>[],'max_age'=>600,'supports_credentials'=>false];
