import time

import schedule
import twint

CSV_FIELDS = ["date", "time", "username", "tweet", "link", "likes", "retweets", "replies", "mentions", "hashtags"]


def fetch(output, username=None, search=None, since="2018-01-01", limit=1000):
    print("Fetching Tweets")
    c = twint.Config()
    c.Username = username
    c.Search = search
    c.Since = since
    c.Limit = limit
    c.Store_csv = True
    c.Custom = CSV_FIELDS
    c.Output = output
    twint.run.Search(c)


# Define your jobs here: each one fetches tweets into its own CSV file.
jobs = [
    lambda: fetch("filename.csv", username="insert username here", search="insert search term here"),
    lambda: fetch("filename2.csv", username="insert username here", search="insert search term here"),
]

for job in jobs:
    job()  # run once at startup
    # Other schedules: schedule.every(1).minutes, .day.at("10:30"), .monday, .wednesday.at("13:15")
    schedule.every().hour.do(job)

while True:
    schedule.run_pending()
    time.sleep(1)
