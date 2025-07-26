# phashboard
PHP dashboard.

I chose this stupid name because originally I'd written this project in rails and called it "rashboard" which I thought was cool and just funny enough, but after [some misadventures off the happy path in rails](https://0x85.org/php.html) I realized I was expending way too much effort just to display some charts and some html for my own personal use, so I wrote the initial version of this in a single 4-hour stretch while flying from Toronto to the west coast.

This dashboard is written for my own use do display relevant performance metrics from my algorithmic trading application, and it expects the following database schema in postgres:

```
SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'trades';

 column_name |        data_type
-------------+--------------------------
 id          | bigint
 dtg         | timestamp with time zone
 symbol      | text
 expiry      | timestamp with time zone
 side        | text
 effect      | text
 strike      | numeric
 price       | numeric
 qty         | integer
 delta       | numeric
 theta       | numeric
 gamma       | numeric
 vega        | numeric
 spot        | numeric
 iv          | numeric
(15 rows)
```
