<template>
  <v-card outlined>
    <v-card-title>
      <v-icon left color="#165134">
        mdi-image-multiple
      </v-icon>

      Property Images

      <v-spacer />

      <v-btn
        v-if="$can('properties.create')"
        small
        color="#165134"
        dark
        @click="$refs.fileInput.$refs.input.click()"
      >
        <v-icon left>mdi-upload</v-icon>
        Upload Images
      </v-btn>
    </v-card-title>

    <v-divider />

    <v-card-text>

      <v-file-input
        v-if="$can('properties.create')"
        ref="fileInput"
        v-model="files"
        multiple
        accept="image/jpeg,image/png,image/webp"
        label="Select property images"
        prepend-icon="mdi-camera"
        show-size
        @change="uploadImages"
      />

      <v-progress-linear
        v-if="uploading"
        indeterminate
        color="#165134"
        class="mb-4"
      />

      <v-row v-if="images.length">

        <v-col
          v-for="image in images"
          :key="image.id"
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >

          <v-card outlined class="image-card">

            <v-img
              :src="image.url"
              height="180"
              contain
              class="grey lighten-4"
            />

            <v-card-actions>

              <v-chip
                v-if="image.is_primary"
                x-small
                color="#165134"
                text-color="white"
              >
                Primary
              </v-chip>

              <v-spacer />

              <v-btn
                v-if="$can('properties.edit') && !image.is_primary"
                icon
                small
                title="Set primary"
                @click="setPrimary(image)"
              >
                <v-icon>
                  mdi-star-outline
                </v-icon>
              </v-btn>

              <v-btn
                v-if="$can('properties.delete')"
                icon
                small
                color="error"
                title="Delete"
                @click="deleteImage(image)"
              >
                <v-icon>
                  mdi-delete
                </v-icon>
              </v-btn>

            </v-card-actions>

          </v-card>

        </v-col>

      </v-row>

      <div
        v-else
        class="text-center grey--text py-10"
      >
        <v-icon
          size="60"
          color="grey lighten-1"
        >
          mdi-image-off-outline
        </v-icon>

        <div class="mt-3">
          No images uploaded.
        </div>
      </div>

    </v-card-text>

  </v-card>
</template>

<script>import api from '../../../services/api'

export default {
  name: 'PropertyImageManager',

  props: {
    propertyId: {
      type: [Number, String],
      required: true
    }
  },

  data() {
    return {
      images: [],
      files: [],
      uploading: false
    }
  },

  mounted() {
    this.loadImages()
  },

  methods: {

    async loadImages() {
      try {
        const response = await api.get(
          `/properties/${this.propertyId}`
        )

        this.images = (response.data.property && response.data.property.images) || response.data.images || []

      } catch (error) {
        this.showMessage(
          'Unable to load property images.',
          'error'
        )
      }
    },

    async uploadImages(files) {

      if (!files || !files.length) {
        return
      }

      const formData = new FormData()

      files.forEach(file => {
        formData.append('images[]', file)
      })

      this.uploading = true

      try {

        await api.post(
          `/properties/${this.propertyId}/images`,
          formData,
          {
            headers: {
              'Content-Type': 'multipart/form-data'
            }
          }
        )

        this.showMessage(
          'Images uploaded successfully.'
        )

        this.files = []

        await this.loadImages()

        this.$emit('updated', this.images)

      } catch (error) {

        this.showMessage(
          error.response?.data?.message ||
          'Image upload failed.',
          'error'
        )

      } finally {
        this.uploading = false
      }
    },

    async setPrimary(image) {

      try {

        await api.put(
          `/property-images/${image.id}/primary`
        )

        this.showMessage(
          'Primary image updated.'
        )

        await this.loadImages()

        this.$emit('updated', this.images)

      } catch (error) {

        this.showMessage(
          'Unable to update primary image.',
          'error'
        )
      }
    },

    async deleteImage(image) {

      if (!confirm(
        'Are you sure you want to delete this image?'
      )) {
        return
      }

      try {

        await api.delete(
          `/property-images/${image.id}`
        )

        this.showMessage(
          'Image deleted successfully.'
        )

        await this.loadImages()

        this.$emit('updated', this.images)

      } catch (error) {

        this.showMessage(
          'Unable to delete image.',
          'error'
        )
      }
    },

    showMessage(message, color = 'success') {
      this.$root.$emit(color === 'error' ? 'show-error' : color === 'warning' ? 'show-warning' : color === 'info' ? 'show-info' : 'show-success', message)
    }
  }
}
</script>

<style scoped>
.image-card {
  overflow: hidden;
  transition: 0.2s ease;
}

.image-card:hover {
  transform: translateY(-2px);
}
</style>