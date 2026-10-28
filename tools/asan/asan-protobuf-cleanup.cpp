// ASan-only lifecycle probe: Protobuf documents that dynamic libraries
// should free their generated static-duration objects on unload.
// Called at PHP module shutdown, after all PHP requests have finished.
#include <google/protobuf/message_lite.h>

extern "C" void wc_asan_protobuf_cleanup(void) {
    google::protobuf::ShutdownProtobufLibrary();
}
