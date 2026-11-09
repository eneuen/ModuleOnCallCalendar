# SIP phones and a fake carrier for recording CDR fixtures.
# baresip comes from Debian's own repository; no sound card is needed
# (a sine tone is sent, received audio is discarded).
FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends baresip-core python3 \
 && rm -rf /var/lib/apt/lists/*
COPY phone.py /callsim/phone.py
ENTRYPOINT ["python3", "/callsim/phone.py"]
