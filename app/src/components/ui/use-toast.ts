export const useToast = () => {
  return {
    toast: ({ title, description, variant }: { title: string; description?: string; variant?: "default" | "destructive" }) => {
      console.log(`Toast: ${title} - ${description} (${variant})`)
      // alert(`${title}\n${description || ''}`); // Optional: user might find annoying
    }
  }
}
