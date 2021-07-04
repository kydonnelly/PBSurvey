#!/usr/bin/env python
from io import BytesIO
import json

from math import ceil

import matplotlib.pyplot as plt
import numpy as np
import mpld3

def uniform_color_cycle(num_groups):
   cycle = [(0.143, 0.846, 0.875, 1), (0.496, 0, 0.973), (1, 0.28, 0.14, 1)]
   start_index = 3 - num_groups
   return cycle[start_index:]

# parse input sent from php
raw_metadata = input()
metadata = json.loads(raw_metadata)
title = metadata['title']
interactive = metadata['interactive']
allocation_groups = metadata['allocations']
output_filename = metadata.get('filename', '')
abbreviations = metadata.get('abbreviations', dict())
horizontal = metadata.get('horizontal', True)
max_width = metadata.get('max_width', 675.0)
max_height = max_width * 7.0 / 9.0

if 'sorted_departments' in metadata:
   departments = metadata['sorted_departments']
   if horizontal:
      departments.reverse()
else:
   department_maxes = dict()
   for group_name, allocations in allocation_groups.items():
      for department, allocation in allocations.items():
         department_maxes[department] = max(department_maxes.get(department, 0.0), allocation)

   all_departments = allocation_groups[list(allocation_groups.keys())[0]].keys()
   departments = sorted(all_departments, key=lambda d: department_maxes[d], reverse=horizontal)

# Order the allocation groups
expected_groups = ["My Vote", "People's Budget", "City Budget"]
ordered_groups = [k for k in expected_groups if k in allocation_groups]

# use color cycle for departments to match other graphs
color_cycle = uniform_color_cycle(len(allocation_groups))
width = 0.84 / len(allocation_groups)
x = range(len(departments))

fig, ax = plt.subplots(figsize=(max_width / 80.0, max_height / 80.0))
plt.title(title, size=12)

group_index = 0
max_percent = 5
for group_name in ordered_groups:
   allocations = allocation_groups[group_name]
   values = [allocations[d] for d in departments]

   if horizontal:
      # show my budget on top
      offset_x = [i + ((len(allocation_groups) - 1) * 0.5 - group_index) * width for i in x]
      container = ax.barh(offset_x, values, width, color=color_cycle[group_index])
   else:
      # show my budget on left
      offset_x = [i + (group_index - (len(allocation_groups) - 1) * 0.5) * width for i in x]
      container = ax.bar(offset_x, values, width, color=color_cycle[group_index])

   if interactive:
      # Add tooltips for each bar
      tooltips = [mpld3.plugins.LineLabelTooltip(container.patches[i], label=str(group_name + ": " + "{:.2f}".format(values[i]) + "%")) for i in range(len(departments))]
      [mpld3.plugins.connect(fig, tooltip) for tooltip in tooltips]

   max_percent = max(max_percent, int(5 * ceil((max(values) + 6) / 5.0)))
   group_index += 1

ticks = range(0, max_percent, 5)
if horizontal:
   plt.xticks(ticks, [str(t) + "%" for t in ticks])
   plt.yticks(x, [abbreviations.get(d, d) for d in departments])
else:
   plt.yticks(ticks, [str(t) + "%" for t in ticks])
   plt.xticks(x, [abbreviations.get(d, d) for d in departments], rotation=60)

for index, department in enumerate(departments):
   values = [allocation_groups[group_name][department] for group_name in ordered_groups]
   if horizontal:
      label = ' | '.join([str(round(v * 10) / 10.0) + "%" for v in values])
      ax.text(max(values) + 1, index, label)
   else:
      label = "\n".join([str(round(v * 10) / 10.0) + "%" for v in values])
      ax.text(index - 0.4, max(values) + 1, label, fontsize=10)

# add legend for each allocation group
legend_colors = [plt.Rectangle((0,0),1,1, color=color_cycle[i]) for i in range(len(allocation_groups))]
if horizontal:
   plt.legend(legend_colors, ordered_groups, bbox_to_anchor=(1.0, 1.0))
else:
   plt.legend(legend_colors, ordered_groups, bbox_to_anchor=(0.3, 1.0))

if interactive:
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
