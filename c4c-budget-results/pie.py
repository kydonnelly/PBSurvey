#!/usr/bin/env python
from io import BytesIO
import json

from matplotlib import cm
import matplotlib.pyplot as plt
import mpld3

# custom label formatting for pie pieces
def make_pct(sigs, group_sum, max_precision):
   def formatted_pct(pct):
      val = pct * group_sum / 100.0
      if pct > 10.0:
         precision = str(int(max_precision))
         format_str = "{v:." + precision + "f}%"
         return format_str.format(v=val)
      elif pct > 4.0:
         precision = str(int(max_precision / 2))
         format_str = "{v:." + precision + "f}%"
         return format_str.format(v=val)
      elif pct > 1.5:
         return "{v:.0f}%".format(v=val)
      else:
         return ""
   return formatted_pct

def wrapping_color_cycle(num_groups):
   color_map = plt.get_cmap('rainbow')
   color_step = int(num_groups / 7) * 1.15 + 1
   return [color_map((color_step * n % num_groups) / num_groups) for n in range(num_groups)]

# parse input sent from php
raw_metadata = input()
metadata = json.loads(raw_metadata)
title = metadata['title']
allocations = metadata['allocations']
interactive = metadata['interactive']
output_filename = metadata.get('filename', '')
max_width = metadata.get('max_width', 675.0)
max_height = max_width * 5.0 / 9.0

if 'sorted_departments' in metadata:
   departments = metadata['sorted_departments']
   values = [allocations[d] for d in departments]
else:
   items = sorted(allocations.items(), key=lambda a: a[1], reverse=True)
   departments = [i[0] for i in items]
   values = [i[1] for i in items]

# custom label formatting setup
max_precision = 1
value_sum = sum(values)
auto_formatting = make_pct(values, value_sum, max_precision)
color_cycle = wrapping_color_cycle(len(departments))

# Alternate pie chart option: https://medium.com/@kvnamipara/a-better-visualisation-of-pie-charts-by-matplotlib-935b7667d77f
fig, ax = plt.subplots(figsize=(27, 15))
if interactive:
   wedges, texts, pct_texts = ax.pie(values, labels=[d if values[i] > 1.0 else '' for i, d in enumerate(departments)], colors=color_cycle, autopct=auto_formatting, pctdistance=0.8, labeldistance=1.033)
else:
   pie_labels = [d if values[i] > 1.0 else '' for i, d in enumerate(departments)]
   wedges, texts, pct_texts = ax.pie(values, labels=pie_labels, colors=color_cycle, autopct=auto_formatting, pctdistance=0.8, labeldistance=1.033)
   legend_labels = [i[1] + ' (' + "{:.1f}".format(values[i[0]]) + "%)" for i in filter(lambda i: values[i[0]] <= 1.0, enumerate(departments))]
   if len(legend_labels) > 0:
      ax.legend(wedges, legend_labels, title="Departments", loc="center right")

ax.axis('equal')
ax.set_axis_off();
ax.set_title(title, size=20)

# Mess around with labels
max_size_amount = value_sum * 0.10
[texts[i].set_fontsize(10 + 8 * min(max_size_amount, values[i]) / max_size_amount) for i in range(len(values))]
[pct_texts[i].set_fontsize(10 + 8 * min(max_size_amount, values[i]) / max_size_amount) for i in range(len(values))]

if interactive:
   # Add tooltips for each pie
   tooltips = [mpld3.plugins.LineLabelTooltip(wedges[i], label=str(departments[i] + ": " + "{:.2f}".format(values[i]) + "%")) for i in range(len(departments))]
   [mpld3.plugins.connect(fig, tooltip) for tooltip in tooltips]
   
   # dump figure to json and print it to the php pipe
   dict = mpld3.fig_to_dict(fig)
   dict['width'] = max_width
   dict['height'] = max_width * 5.0 / 9.0
   json = json.dumps(dict)
   print(json)
else:
   # buf = BytesIO()
   plt.savefig(output_filename, format='png', bbox_inches='tight')
   # buf.seek(0)
   # print(buf.read())
   # buf.close()

plt.clf()
