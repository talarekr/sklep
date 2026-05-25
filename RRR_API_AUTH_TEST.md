# RRR API – pierwszy test autoryzacji (read-only)

Rekomendowany endpoint testowy: `POST https://api.rrr.lt/v2/get/parts?limit=1&page=1`

Wymagane pola form-data:
- username
- password
- user_token

Alternatywnie test pojedynczej części:
`POST https://api.rrr.lt/get/part/{id}`
