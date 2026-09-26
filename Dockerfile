FROM python:3.8-buster

WORKDIR /opt/twint
COPY . .
RUN pip3 install --no-cache-dir -r requirements.txt . && \
	rm -rf /opt/twint

WORKDIR /root
CMD ["/bin/bash"]
