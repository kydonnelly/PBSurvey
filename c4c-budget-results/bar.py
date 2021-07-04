#!/usr/bin/env python
from io import BytesIO
import json

from math import ceil

from matplotlib import cm
import matplotlib.pyplot as plt
import numpy as np
import mpld3

def wrapping_color_cycle(num_groups):
   color_map = plt.get_cmap('rainbow')
   color_step = int(num_groups / 7) * 1.15 + 1
   return [color_map(1.0 - (color_step * n % num_groups) / num_groups) for n in range(num_groups)]

# parse input sent from php
raw_metadata = input()
metadata = json.loads(raw_metadata)
title = metadata['title']
allocations = metadata['allocations']
interactive = metadata['interactive']
output_filename = metadata.get('filename', '')
abbreviations = metadata.get('abbreviations', dict())
max_width = metadata.get('max_width', 675.0)
max_height = max_width * 5.0 / 9.0

if 'sorted_departments' in metadata:
   departments = metadata['sorted_departments']
   departments.reverse()
   values = [allocations[d] for d in departments]
else:
   items = sorted(allocations.items(), key=lambda a: a[1], reverse=True)
   departments = [i[0] for i in items]
   values = [i[1] for i in items]

# custom color cycle
color_cycle = wrapping_color_cycle(len(departments))

x = range(len(departments))
fig, ax = plt.subplots(figsize=(max_width / 80.0, max_height / 80.0))
container = ax.barh(x, values, color=color_cycle)
plt.title(title, size=12)
plt.yticks(x, [abbreviations.get(d, d) for d in departments])
max_percent = int(5 * ceil((max(values) + 7) / 5.0))
xticks = range(0, max_percent, 5)
plt.xticks(xticks, [str(i) + "%" for i in xticks])
for i, v in enumerate(values):
   ax.text(v + 0.25, i - 0.2, str(round(v * 10) / 10.0) + "%", color=[c * 0.5 for c in color_cycle[i]])

if interactive:
   # Add tooltips for each bar
   tooltips = [mpld3.plugins.LineLabelTooltip(container.patches[i], label=str(departments[i] + ": " + "{:.2f}".format(values[i]) + "%")) for i in range(len(departments))]
   [mpld3.plugins.connect(fig, tooltip) for tooltip in tooltips]
   
   # fix json error with numpy values. https://stackoverflow.com/a/50577730
   def convert(o):
      if isinstance(o, np.int64): return int(o)
      raise TypeError
   
   # dump figure to json and print it to the php pipe
   dict = mpld3.fig_to_dict(fig)
   json = json.dumps(dict, default=convert)
   print(json)
else:
   # buf = BytesIO()
   plt.savefig(output_filename, format='png', bbox_inches='tight')
   # buf.seek(0)
   # print(buf.read())
   # buf.close()

plt.clf()
