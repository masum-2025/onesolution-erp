# School websites: addresses, own domains and certificates

Public school websites (module `edu_website`) answer on three kinds of address:

| Address | Example | Needs |
|---|---|---|
| Path on the app host | `https://app.example.com/s/abc-school` | nothing extra |
| Sites domain | `https://abc-school.sites.example.com` | wildcard DNS `*.sites.example.com` and a wildcard certificate |
| The school's own domain | `https://www.abcschool.edu.bd` | the school's DNS records (below) and a certificate made on demand |

## Settings (`config/sites.php`, `.env`)

- `SITES_DOMAIN`: the sites domain (placeholder `sites.localhost`).
- `SITES_CNAME_TARGET`: the name schools point their domain at with a CNAME
  (default `cname.{SITES_DOMAIN}`); it must resolve to the sites server.
- `SITES_SERVER_IP`: the server's public address for bare domains (`abcschool.edu.bd`), which
  cannot have a CNAME. Shown to schools as an A record when set.

## What a school does

1. In **Website → Settings → Own domain** (people with `edu_website.publish`; only when the rule
   `edu_website.own_domain` is on for its plan or partner), it writes the domain.
2. Its DNS gets two records, shown on that screen with copy buttons:
   - `CNAME www.abcschool.edu.bd → {SITES_CNAME_TARGET}` (or the A record);
   - `TXT _onesolution-site.www.abcschool.edu.bd → onesolution-site={token}`.
3. **Check now**: when the TXT record carries the token, the domain is proven
   (`site_addresses.domain_verified_at`, audited). From then on the site opens there, and its
   sites-domain address answers `301` to the own domain.

## Certificates on demand

A certificate for each school's domain is made by the web server the first time the domain is
visited, after asking the app whether the name is ours:

```
GET /s/_tls?domain=www.abcschool.edu.bd   →   200 (make it)   |   404 (refuse)
```

It says yes only for a site's sites-domain address that exists, or a proven own domain. It has
no session or cookie and allows 600 checks a minute per address.

### Caddy example

```
{
    on_demand_tls {
        ask http://127.0.0.1:8000/s/_tls
    }
}

https:// {
    tls {
        on_demand
    }
    reverse_proxy 127.0.0.1:8000
}
```

`*.sites.example.com` can use the same rule, or a wildcard certificate by DNS challenge.

## Search engines

Each page names one address (`<link rel="canonical">`): the own domain once proven, else the
sites-domain address. Pages give their other languages (`hreflang`, `x-default`), `sitemap.xml`
and `robots.txt` are made from the published pages and notices, and a preview is never indexed.
