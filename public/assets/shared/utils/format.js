export function uniqueFilters(values) {
  return [...new Set(values.filter((value) => value !== undefined && value !== null && String(value) !== ''))]
    .sort((a, b) => String(a).localeCompare(String(b)))
    .map((value) => ({ text: String(value), value }))
}

export function regionName(regions, id) {
  return regions && regions[id] ? regions[id] : id
}
