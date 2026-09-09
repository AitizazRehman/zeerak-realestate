<template>
  <v-card outlined>

    <v-card-title>
      <v-icon left color="#165134">
        mdi-map-marker-multiple
      </v-icon>

      Property Map

      <v-spacer />

      <v-chip small>
        {{ mappedProperties.length }} properties
      </v-chip>
    </v-card-title>

    <v-divider />

    <div
      ref="map"
      class="property-map"
    />

  </v-card>
</template>

<script>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

export default {
  name: 'PropertyMap',

  props: {
    properties: {
      type: Array,
      default: () => []
    },

    height: {
      type: String,
      default: '550px'
    }
  },

  data() {
    return {
      map: null,
      markers: []
    }
  },

  computed: {

    mappedProperties() {
      return this.properties.filter(property => {
        return (
          property.latitude !== null &&
          property.latitude !== undefined &&
          property.longitude !== null &&
          property.longitude !== undefined
        )
      })
    }
  },

  mounted() {
    this.initializeMap()
  },

  beforeDestroy() {
    if (this.map) {
      this.map.remove()
    }
  },

  watch: {

    properties: {
      deep: true,

      handler() {
        this.$nextTick(() => {
          this.updateMarkers()
        })
      }
    }
  },

  methods: {

    initializeMap() {

      this.map = L.map(this.$refs.map).setView(
        [33.6844, 73.0479],
        12
      )

      L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
          attribution:
            '&copy; OpenStreetMap contributors'
        }
      ).addTo(this.map)

      this.updateMarkers()
    },

    updateMarkers() {

      if (!this.map) {
        return
      }

      this.markers.forEach(marker => {
        this.map.removeLayer(marker)
      })

      this.markers = []

      const bounds = []

      this.mappedProperties.forEach(property => {

        const lat = parseFloat(property.latitude)
        const lng = parseFloat(property.longitude)

        if (
          Number.isNaN(lat) ||
          Number.isNaN(lng)
        ) {
          return
        }

        const marker = L.marker([
          lat,
          lng
        ]).addTo(this.map)

        marker.bindPopup(`
          <div style="min-width:220px">
            <strong>
              ${property.property_number || ''}
            </strong>

            <br>

            ${property.project?.name || ''}

            <br>

            ${property.block?.name || ''}

            <br><br>

            <strong>
              PKR ${this.formatPrice(property.price)}
            </strong>

            <br>

            Status:
            ${property.status || ''}

            <br><br>

            <a
              href="/admin/properties/${property.id}"
              style="color:#165134"
            >
              View Property
            </a>
          </div>
        `)

        this.markers.push(marker)

        bounds.push([
          lat,
          lng
        ])
      })

      if (bounds.length === 1) {

        this.map.setView(
          bounds[0],
          15
        )

      } else if (bounds.length > 1) {

        this.map.fitBounds(
          bounds,
          {
            padding: [30, 30]
          }
        )
      }
    },

    formatPrice(value) {

      if (!value) {
        return '0'
      }

      return new Intl.NumberFormat(
        'en-PK'
      ).format(value)
    }
  }
}
</script>

<style scoped>
.property-map {
  width: 100%;
  height: 550px;
  z-index: 1;
}
</style>