# SSL Certificate Configuration

This directory contains SSL certificates for HTTPS configuration.

## For Development (Self-Signed Certificate)

Generate a self-signed certificate for development:

```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout docker/ssl/private.key \
  -out docker/ssl/certificate.crt \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=localhost"
```

## For Production (Let's Encrypt)

### Option 1: Manual Certificate

1. Obtain certificates from Let's Encrypt or your SSL provider
2. Copy certificate files to this directory:
   - `certificate.crt` - Your SSL certificate
   - `private.key` - Your private key
   - `ca_bundle.crt` - Certificate chain (optional)

### Option 2: Certbot with Docker

Use certbot to automatically obtain certificates:

```bash
# Install certbot
docker run -it --rm \
  -v /etc/letsencrypt:/etc/letsencrypt \
  -v /var/lib/letsencrypt:/var/lib/letsencrypt \
  -p 80:80 \
  certbot/certbot certonly \
  --standalone \
  -d your-domain.com

# Copy certificates
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem docker/ssl/certificate.crt
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem docker/ssl/private.key
```

### Option 3: Automated Renewal with Certbot

Add to docker-compose.production.yml:

```yaml
certbot:
  image: certbot/certbot
  volumes:
    - ./docker/ssl:/etc/letsencrypt
    - ./docker/certbot:/var/www/certbot
  entrypoint: "/bin/sh -c 'trap exit TERM; while :; do certbot renew; sleep 12h & wait $${!}; done;'"
```

## Security Best Practices

1. **Never commit private keys to version control**
2. Set proper file permissions:
   ```bash
   chmod 600 docker/ssl/private.key
   chmod 644 docker/ssl/certificate.crt
   ```
3. Use strong ciphers (already configured in nginx)
4. Renew certificates before expiration
5. Consider using HSTS headers (already enabled)

## Testing SSL Configuration

Test your SSL setup:

```bash
# Check certificate
openssl x509 -in docker/ssl/certificate.crt -text -noout

# Test SSL connection
openssl s_client -connect localhost:443 -servername localhost

# Online SSL test (production only)
# Visit: https://www.ssllabs.com/ssltest/
```

## Troubleshooting

### Certificate Not Found Error
- Ensure certificate files exist in this directory
- Check file permissions
- Verify nginx configuration points to correct paths

### Certificate Expired
- Renew certificate with your provider
- Update certificate files
- Restart nginx: `docker compose restart nginx`

### Mixed Content Warnings
- Ensure all resources load via HTTPS
- Update APP_URL in .env to use https://
- Check for hardcoded HTTP URLs in code
