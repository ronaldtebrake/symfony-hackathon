# Official multi-platform PHP image; refresh deliberately and rerun the isolation suite.
FROM php:8.4-cli-alpine@sha256:d8784155c4d37986be9ddd7a8950df7e78998cc48e35472cd69e2b9371424a65

LABEL org.opencontainers.image.title="PHP Code Mode runner"
WORKDIR /runner
COPY bootstrap.php /runner/bootstrap.php
RUN chmod 0444 /runner/bootstrap.php
USER 65532:65532
ENTRYPOINT ["/usr/local/bin/php", "-n", "/runner/bootstrap.php"]
